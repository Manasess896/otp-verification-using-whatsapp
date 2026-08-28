<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

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
  $codesJson = file_get_contents('codes.json');
  if ($codesJson === false) {
    throw new Exception("codes.json not found or is not readable.");
  }
  $countryCodes = json_decode($codesJson, true);
  if (json_last_error() !== JSON_ERROR_NONE) {
    throw new Exception("Error decoding codes.json: " . json_last_error_msg());
  }
} catch (Exception $e) {
 
}


try {
  $client = new Client($mongoUri);
  $otpCollection = $client->$dbName->otp_codes;
  $whatsappNumbersCollection = $client->$dbName->whatsapp_numbers;
} catch (Exception $e) {
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => "Failed to connect to MongoDB: " . $e->getMessage()]);
  exit;
}

if (isset($_POST['action'])) {
  header('Content-Type: application/json');
  $response = ['success' => false, 'message' => 'Invalid request.'];

  if (isset($_POST['action']) && $_POST['action'] === 'send_otp') {
    $dialCode = trim($_POST['dial_code'] ?? '');
    $phoneBody = trim($_POST['phone'] ?? '');
    $userPhone = $dialCode . $phoneBody;

    $selectedCountry = null;
    foreach ($countryCodes as $country) {
      if ($country['dial_code'] === str_replace('+', '', $dialCode)) {
        $selectedCountry = $country;
        break;
      }
    }

    if (!$selectedCountry) {
      $response['message'] = "Invalid country code selected.";
    } elseif (empty($phoneBody) || !ctype_digit($phoneBody)) {
      $response['message'] = "Please enter a valid phone number (digits only).";
    } elseif (isset($selectedCountry['mobile_number_length']) && strlen($phoneBody) != $selectedCountry['mobile_number_length']) {
      $response['message'] = "Invalid phone number length for " . $selectedCountry['name'] . ". Expected " . $selectedCountry['mobile_number_length'] . " digits.";
    } else {
      try {
        $otp = random_int(100000, 999999);
        $expiryMinutes = 10;
        $expiresAt = new UTCDateTime((new DateTime("+$expiryMinutes minutes"))->getTimestamp() * 1000);

        $url = "https://graph.facebook.com/v21.0/$phoneNumberId/messages";
        $payload = [
          "messaging_product" => "whatsapp",
          "to" => $userPhone,
          "type" => "template",
          "template" => [
            "name" => "otp1",//this is the name that appears in the whatsapp template dashboard if wrong it will bring the template does not exsist error
            "language" => ["code" => "en_US"], // here you entered the lunguage you choose for the template if wrong it will bring translation error
            "components" => [
              ["type" => "body", "parameters" => [["type" => "text", "text" => (string)$otp]]],
              ["type" => "button", "sub_type" => "url", "index" => 0, "parameters" => [["type" => "text", "text" => (string)$otp]]]
            ]
          ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken", "Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $apiResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
         
          $otpCollection->deleteMany(["phone" => $userPhone]); 
          $otpHash = password_hash((string)$otp, PASSWORD_DEFAULT);
          $otpCollection->insertOne([
            "phone" => $userPhone,
            "otp" => $otpHash,
            "expires_at" => $expiresAt,
            "verified" => false,
            "created_at" => new UTCDateTime()
          ]);
          $response = ['success' => true, 'message' => "OTP sent to $userPhone. Enter it below to verify.", 'phone' => $userPhone];
        } else {
          $errorBody = json_decode($apiResponse, true);
          $errorMessage = $errorBody['error']['message'] ?? $apiResponse;
          if ($curlError) {
            $errorMessage = "cURL Error: " . $curlError;
          }
          $response['message'] = "Failed to send OTP. API Error (HTTP $httpCode): " . $errorMessage;
        }
      } catch (Exception $e) {
        $response['message'] = "An error occurred: " . $e->getMessage();
      }
    }
  }
 
  elseif (isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
    $userPhone = trim($_POST['verify_phone'] ?? '');
    $userOtp   = trim($_POST['otp'] ?? '');

    if (empty($userPhone)) {
      $response['message'] = "Phone number is missing. Please try sending the OTP again.";
    } elseif (!preg_match('/^\d{6}$/', $userOtp)) {
      $response['message'] = "Invalid OTP format. Please enter a 6-digit code.";
    } else {
      $record = $otpCollection->findOne(
        ["phone" => $userPhone, "verified" => false],
        ["sort" => ["created_at" => -1]]
      );

      if (!$record) {
        $response['message'] = "No active OTP found for this number. It may have expired or been verified already.";
      } elseif ((new DateTime())->getTimestamp() * 1000 > $record['expires_at']->toDateTime()->getTimestamp() * 1000) {
        $response['message'] = "OTP has expired. Please request a new one.";
      } elseif (password_verify($userOtp, $record['otp'])) {
      
        $otpCollection->deleteOne(["_id" => $record['_id']]);
        $whatsappNumbersCollection->updateOne(
          ['phone' => $userPhone],
          ['$set' => ['phone' => $userPhone, 'verified_at' => new UTCDateTime()]],
          ['upsert' => true]
        );
        $response = ['success' => true, 'message' => 'OTP verified successfully! Your number is now registered.'];
      } else {
        $response['message'] = "Incorrect OTP. Please try again.";
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
    body {
      background-color: #f8f9fa;
    }

    .container {
      max-width: 500px;
    }

    .card {
      border: none;
      border-radius: 0.75rem;
    }

    .select2-container .select2-selection--single {
      height: 38px;
      border: 1px solid #ced4da;
    }

    .select2-container--default .select2-selection--single .select2-selection__rendered {
      line-height: 36px;
    }

    .select2-container--default .select2-selection--single .select2-selection__arrow {
      height: 36px;
    }

    #verify-form {
      display: none;
    }
  </style>
</head>

<body class="bg-light">
  <div class="container mt-5">
    <div class="card p-4 shadow-sm">
      <h2 class="mb-4 text-center">WhatsApp OTP Verification</h2>

      <!-- Send OTP Form -->
      <form id="send-otp-form" class="mb-3">
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

      <form id="verify-form">
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
      $('#dial_code').select2({
        placeholder: "Select a country"
      });
    });

    document.addEventListener('DOMContentLoaded', function() {
      const sendForm = document.getElementById('send-otp-form');
      const verifyForm = document.getElementById('verify-form');
      const verifyPhoneInput = document.getElementById('verify_phone');
      const phoneDisplay = document.getElementById('phone-display');
      const resendBtn = document.getElementById('resend-btn');

      const handleFetch = (url, formData, loadingTitle) => {
        Swal.fire({
          title: loadingTitle,
          text: 'Please wait.',
          allowOutsideClick: false,
          didOpen: () => {
            Swal.showLoading();
          }
        });

        return fetch(url, {
          method: 'POST',
          body: formData
        }).then(response => {
          if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }
          return response.json();
        }).catch(error => {
          Swal.fire({
            icon: 'error',
            title: 'Network Error',
            text: 'Could not connect to the server. Please check your connection and try again.',
          });
          return Promise.reject(error);
        });
      };

      const handleSendOtp = (e) => {
        e.preventDefault();
        const formData = new FormData(sendForm);
        handleFetch('index.php', formData, 'Sending OTP...')
          .then(data => {
            if (data && data.success) {
              Swal.fire({
                icon: 'success',
                title: 'OTP Sent!',
                text: data.message,
              });
              verifyPhoneInput.value = data.phone;
              phoneDisplay.textContent = data.phone;
              sendForm.style.display = 'none';
              verifyForm.style.display = 'block';
            } else if (data) {
              Swal.fire({
                icon: 'error',
                title: 'Oops...',
                text: data.message,
              });
            }
          });
      };

    
      sendForm.addEventListener('submit', handleSendOtp);
      resendBtn.addEventListener('click', (e) => {
        
        handleSendOtp(e);
      });


      verifyForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(verifyForm);

        handleFetch('index.php', formData, 'Verifying OTP...')
          .then(data => {
            if (data && data.success) {
              Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: data.message,
              }).then(() => {
            
                verifyForm.style.display = 'none';
                sendForm.style.display = 'block';
                sendForm.reset();
                verifyForm.reset();
                $('#dial_code').val(null).trigger('change'); // Reset Select2
              });
            } else if (data) {
              Swal.fire({
                icon: 'error',
                title: 'Verification Failed',
                text: data.message,
              });
            }
          });
      });
    });
  </script>

</body>

</html>