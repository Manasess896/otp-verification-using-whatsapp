<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require 'vendor/autoload.php';

use MongoDB\Client;
use MongoDB\BSON\UTCDateTime;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();
$accessToken   = $_ENV['WHATSAPP_TOKEN']   ?? getenv('WHATSAPP_TOKEN');
$phoneNumberId = $_ENV['PHONE_NUMBER_ID']  ?? getenv('PHONE_NUMBER_ID');
$mongoUri      = $_ENV['MONGODB_URI']      ?? getenv('MONGODB_URI');
$dbName        = $_ENV['MONGODB_DATABASE'] ?? getenv('MONGODB_DATABASE');

$countryCodes = [];
try {
    $codesJson = @file_get_contents('codes.json');
    if ($codesJson !== false) {
        $countryCodes = json_decode($codesJson, true) ?? [];
    }
} catch (Exception $e) {
    error_log("Failed loading codes.json: " . $e->getMessage());
}

try {
    $client = new Client($mongoUri);
    $otpCollection = $client->$dbName->otp_codes;
    $whatsappNumbersCollection = $client->$dbName->whatsapp_numbers;
} catch (Exception $e) {
    error_log("MongoDB Connection Error: " . $e->getMessage());
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => "Database connection error. Please try again later."]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Invalid request.'];

    // 2. Validate CSRF Token
    $userCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $userCsrf)) {
        echo json_encode(['success' => false, 'message' => 'CSRF validation failed. Please refresh the page and try again.']);
        exit;
    }

    if ($_POST['action'] === 'send_otp') {
        $dialCode  = filter_var(trim($_POST['dial_code'] ?? ''), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $phoneBody = filter_var(trim($_POST['phone'] ?? ''), FILTER_SANITIZE_NUMBER_INT);
        $userPhone = preg_replace('/[^0-9+]/', '', $dialCode . $phoneBody);

        $selectedCountry = null;
        $cleanDialCode = str_replace('+', '', $dialCode);
        foreach ($countryCodes as $country) {
            if (($country['dial_code'] ?? '') === $cleanDialCode) {
                $selectedCountry = $country;
                break;
            }
        }

        if (!$selectedCountry) {
            $response['message'] = "Invalid country code selected.";
        } elseif (empty($phoneBody) || !ctype_digit($phoneBody)) {
            $response['message'] = "Please enter a valid phone number (digits only).";
        } elseif (isset($selectedCountry['mobile_number_length']) && strlen($phoneBody) != $selectedCountry['mobile_number_length']) {
            $response['message'] = "Invalid phone number length for " . htmlspecialchars($selectedCountry['name']) . ". Expected " . $selectedCountry['mobile_number_length'] . " digits.";
        } else {
            try {
                //
                $recentOtp = $otpCollection->findOne([
                    "phone" => (string)$userPhone,
                    "created_at" => ['$gt' => new UTCDateTime((time() - 60) * 1000)]
                ]);

                if ($recentOtp) {
                    $response['message'] = "Please wait 60 seconds before requesting another OTP.";
                    echo json_encode($response);
                    exit;
                }

                $otp = (string)random_int(100000, 999999);
                $expiryMinutes = 10;
                $expiresAt = new UTCDateTime((new DateTime("+$expiryMinutes minutes"))->getTimestamp() * 1000);

                $url = "https://graph.facebook.com/v21.0/" . urlencode($phoneNumberId) . "/messages";
                $payload = [
                    "messaging_product" => "whatsapp",
                    "to" => $userPhone,
                    "type" => "template",
                    "template" => [
                        "name" => "otp1",
                        "language" => ["code" => "en_US"],
                        "components" => [
                            ["type" => "body", "parameters" => [["type" => "text", "text" => $otp]]],
                            ["type" => "button", "sub_type" => "url", "index" => 0, "parameters" => [["type" => "text", "text" => $otp]]]
                        ]
                    ]
                ];

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken", "Content-Type: application/json"]);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                $apiResponse = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($httpCode >= 200 && $httpCode < 300) {
                    $otpCollection->deleteMany(["phone" => (string)$userPhone]); 
                    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
                    $otpCollection->insertOne([
                        "phone" => (string)$userPhone,
                        "otp" => $otpHash,
                        "attempts" => 0,
                        "expires_at" => $expiresAt,
                        "verified" => false,
                        "created_at" => new UTCDateTime()
                    ]);
                    $response = ['success' => true, 'message' => "OTP sent successfully. Enter it below to verify.", 'phone' => $userPhone];
                } else {
                    error_log("WhatsApp API Failure ($httpCode): $apiResponse | Curl Error: $curlError");
                    $response['message'] = "Failed to send OTP. Please try again later.";
                }
            } catch (Exception $e) {
                error_log("Send OTP Exception: " . $e->getMessage());
                $response['message'] = "An unexpected error occurred. Please try again.";
            }
        }
    }
    elseif ($_POST['action'] === 'verify_otp') {
        $userPhone = preg_replace('/[^0-9+]/', '', (string)($_POST['verify_phone'] ?? ''));
        $userOtp   = trim((string)($_POST['otp'] ?? ''));

        if (empty($userPhone)) {
            $response['message'] = "Phone number is missing. Please try sending the OTP again.";
        } elseif (!preg_match('/^\d{6}$/', $userOtp)) {
            $response['message'] = "Invalid OTP format. Please enter a 6-digit code.";
        } else {
            $record = $otpCollection->findOne(
                ["phone" => (string)$userPhone, "verified" => false],
                ["sort" => ["created_at" => -1]]
            );

            if (!$record) {
                $response['message'] = "No active OTP found. It may have expired or been verified.";
            } elseif (isset($record['attempts']) && $record['attempts'] >= 3) {
                // 4. Invalidate code on max attempts
                $otpCollection->deleteOne(["_id" => $record['_id']]);
                $response['message'] = "Too many failed attempts. Please request a new OTP.";
            } elseif ((new DateTime())->getTimestamp() * 1000 > $record['expires_at']->toDateTime()->getTimestamp() * 1000) {
                $response['message'] = "OTP has expired. Please request a new one.";
            } elseif (password_verify($userOtp, $record['otp'])) {
                $otpCollection->deleteOne(["_id" => $record['_id']]);
                $whatsappNumbersCollection->updateOne(
                    ['phone' => (string)$userPhone],
                    ['$set' => ['phone' => (string)$userPhone, 'verified_at' => new UTCDateTime()]],
                    ['upsert' => true]
                );
                $response = ['success' => true, 'message' => 'OTP verified successfully! Your number is registered.'];
            } else {
                // Increment failed attempt count
                $otpCollection->updateOne(
                    ["_id" => $record['_id']],
                    ['$inc' => ['attempts' => 1]]
                );
                $remaining = 3 - (($record['attempts'] ?? 0) + 1);
                $response['message'] = "Incorrect OTP. " . ($remaining > 0 ? "You have $remaining attempt(s) left." : "Too many attempts.");
            }
        }
    }

    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>WhatsApp OTP Verification</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body { background-color: #f8f9fa; }
    .container { max-width: 500px; }
    .card { border: none; border-radius: 0.75rem; }
    .select2-container .select2-selection--single { height: 38px; border: 1px solid #ced4da; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 36px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
    #verify-form { display: none; }
  </style>
</head>
<body class="bg-light">
  <div class="container mt-5">
    <div class="card p-4 shadow-sm">
      <h2 class="mb-4 text-center">WhatsApp OTP Verification</h2>

      <!-- Send OTP Form -->
      <form id="send-otp-form" class="mb-3">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <div class="form-group">
          <label for="phone">Phone Number:</label>
          <div class="input-group">
            <div class="input-group-prepend" style="max-width: 150px;">
              <select id="dial_code" name="dial_code" class="form-control" required>
                <option value="" disabled selected>Country</option>
                <?php if (!empty($countryCodes)): ?>
                  <?php foreach ($countryCodes as $country): ?>
                    <?php if (!empty($country['dial_code']) && !empty($country['name'])): ?>
                      <option value="+<?php echo htmlspecialchars(ltrim($country['dial_code'], '+')); ?>">
                        <?php echo htmlspecialchars($country['name']) . " (+" . htmlspecialchars(ltrim($country['dial_code'], '+')) . ")"; ?>
                      </option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>
            <input type="tel" id="phone" name="phone" class="form-control" placeholder="e.g., 712345678" required>
          </div>
          <small class="form-text text-muted">Select your country and enter your phone number without the country code.</small>
        </div>
        <input type="hidden" name="action" value="send_otp">
        <button type="submit" class="btn btn-success btn-block">Send OTP</button>
      </form>

      <!-- Verify OTP Form -->
      <form id="verify-form">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <p class="text-center">An OTP has been sent to <strong id="phone-display"></strong>.</p>
        <div class="form-group">
          <label for="otp">Enter OTP:</label>
          <input type="text" id="otp" name="otp" class="form-control" placeholder="6-digit code" required pattern="\d{6}">
          <input type="hidden" id="verify_phone" name="verify_phone">
        </div>
        <input type="hidden" name="action" value="verify_otp">
        <button type="submit" class="btn btn-primary btn-block">Verify OTP</button>
        <button type="button" id="resend-btn" class="btn btn-link btn-block mt-2">Send again</button>
      </form>
    </div>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
  <script>
    $(document).ready(function() {
      $('#dial_code').select2({ placeholder: "Select a country" });
    });

    document.addEventListener('DOMContentLoaded', function() {
      const sendForm = document.getElementById('send-otp-form');
      const verifyForm = document.getElementById('verify-form');
      const verifyPhoneInput = document.getElementById('verify_phone');
      const phoneDisplay = document.getElementById('phone-display');
      const resendBtn = document.getElementById('resend-btn');

      const handleFetch = (formData, loadingTitle) => {
        Swal.fire({
          title: loadingTitle,
          text: 'Please wait.',
          allowOutsideClick: false,
          didOpen: () => { Swal.showLoading(); }
        });

        return fetch(window.location.pathname, {
          method: 'POST',
          body: formData
        }).then(response => {
          if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
          return response.json();
        }).catch(error => {
          Swal.fire({
            icon: 'error',
            title: 'Network Error',
            text: 'Could not connect to the server. Please check your connection.',
          });
          return Promise.reject(error);
        });
      };

      const handleSendOtp = (e) => {
        e.preventDefault();
        const formData = new FormData(sendForm);
        handleFetch(formData, 'Sending OTP...')
          .then(data => {
            if (data && data.success) {
              Swal.fire({ icon: 'success', title: 'OTP Sent!', text: data.message });
              verifyPhoneInput.value = data.phone;
              phoneDisplay.textContent = data.phone;
              sendForm.style.display = 'none';
              verifyForm.style.display = 'block';
            } else if (data) {
              Swal.fire({ icon: 'error', title: 'Oops...', text: data.message });
            }
          });
      };

      sendForm.addEventListener('submit', handleSendOtp);
      resendBtn.addEventListener('click', handleSendOtp);

      verifyForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(verifyForm);

        handleFetch(formData, 'Verifying OTP...')
          .then(data => {
            if (data && data.success) {
              Swal.fire({ icon: 'success', title: 'Success!', text: data.message }).then(() => {
                verifyForm.style.display = 'none';
                sendForm.style.display = 'block';
                sendForm.reset();
                verifyForm.reset();
                $('#dial_code').val(null).trigger('change');
              });
            } else if (data) {
              Swal.fire({ icon: 'error', title: 'Verification Failed', text: data.message });
            }
          });
      });
    });
  </script>
</body>
</html>