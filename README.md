# WhatsApp OTP Verification using PHP and Meta API

This project demonstrates how to implement a user verification system using WhatsApp for sending One-Time Passwords (OTPs). It's built with PHP, uses MongoDB for storing data, and integrates with the official Meta WhatsApp Business API.

## Features

- **Secure OTP Flow**: Sends OTP via WhatsApp API and only saves it to the database upon successful transmission.
- **Dynamic Country Codes**: Populates a searchable dropdown with country codes from a JSON file.
- **Phone Number Validation**: Validates phone number length based on the selected country.
- **AJAX-driven**: Smooth user experience without page reloads.
- **User-Friendly Interface**: Clean UI with SweetAlert2 for notifications.
- **Environment-based Configuration**: Keeps your sensitive credentials out of the code using a `.env` file.

## Prerequisites

- **PHP**: Version 7.4 or higher.
- **Web Server**: Apache or Nginx (XAMPP, WAMP, or MAMP are fine for local development).
- **Composer**: For managing PHP dependencies.
- **MongoDB**: A running MongoDB instance (local or a cloud service like MongoDB Atlas).
- **A Meta (Facebook) Developer Account**.
- **A WhatsApp Business Account**.
- **A registered phone number** that can make and receive WhatsApp calls/messages.

---

## Project Setup

1.  **Clone the Repository**:

    ```bash
    git clone <your-repository-url>
    cd <your-repository-folder>
    ```

2.  **Install Dependencies**:
    Run Composer to install the required PHP libraries (MongoDB driver, Dotenv).

    ```bash
    composer install
    ```

3.  **Configure Environment Variables**:
    Create a `.env` file in the root of the project by copying the example file.

    ```bash
    copy .env.example .env
    ```

    Now, open the `.env` file and fill in your specific credentials.

    ```dotenv
    # .env

    # --- Meta WhatsApp API Credentials ---
    # Your permanent or temporary access token from the Meta App Dashboard
    WHATSAPP_TOKEN="YOUR_WHATSAPP_API_TOKEN"

    # The Phone Number ID for your sending number, found in the Meta App Dashboard
    PHONE_NUMBER_ID="YOUR_SENDER_PHONE_NUMBER_ID"

    # --- MongoDB Configuration ---
    # Your MongoDB connection string
    MONGODB_URI="mongodb://localhost:27017"

    # The name of the database you want to use
    MONGODB_DATABASE="whatsapp_verification"
    ```

---

## Meta & WhatsApp API Setup Guide

Setting up the WhatsApp API involves several steps across two different websites: the **Meta for Developers** site (`developers.facebook.com`) for app configuration and the **Meta Business Portfolio** (`business.facebook.com`) for managing business assets like phone numbers and message templates.

### Step 1: Prerequisites - A Facebook Account

You must have a personal Facebook account to create a Meta Developer account and a Meta Business Portfolio.

### Step 2: Create a Meta Business Portfolio

The Business Portfolio (formerly Business Manager) is where you manage all your business assets.

1.  Go to the [Meta Business Suite](https://business.facebook.com/) and log in with your Facebook account.
2.  If you don't have a Business Account, you will be prompted to create one. Follow the on-screen instructions to create your business portfolio. You'll need to provide a business name, your name, and a business email.

### Step 3: Create a Meta App

The app connects your code to Meta's services.

1.  Go to [Meta for Developers](https://developers.facebook.com/) and click **My Apps**.
2.  Click **Create App**.
3.  Select **Other** as the use case, then select **Business** as the app type.
4.  Provide an app name and your contact email.
5.  Crucially, when prompted, select the **Business Portfolio** you created in the previous step to link the app to it.

### Step 4: Set Up the WhatsApp Product & Get Test Credentials

1.  From your app's dashboard on the developer site, find the "WhatsApp" product and click **Set up**.
2.  In the sidebar, under "WhatsApp", click **API Setup**.
3.  On this page, you will find your initial test credentials:
    -   A temporary **Access Token** (expires in 24 hours).
    -   A **Phone Number ID** associated with a test phone number provided by Meta.
    -   These are great for initial testing but are not for production.

### Step 5: Generate a Permanent Access Token (for Production)

For a live application, you need a token that doesn't expire.

1.  Navigate to your **Meta Business Portfolio**: `business.facebook.com`.
2.  Go to **Settings > Business Settings**.
3.  Under **Users**, click on **System Users**.
4.  **Add** a new System User. Give it a name (e.g., "WebAppSystemUser") and set the role to **Admin**.
5.  **Assign Assets**:
    -   Select the System User you just created.
    -   Click **Add Assets**.
    -   In the "Select asset type" column, choose **Apps**.
    -   Select your app from the list.
    -   Enable the **Manage App** permission.
6.  **Generate Token**:
    -   Click the **Generate new token** button for that System User.
    -   Select your app from the dropdown.
    -   Under "Available permissions", select `whatsapp_business_management` and `whatsapp_business_messaging`.
    -   Click **Generate Token**.
    -   **Important**: Copy and save this token immediately. This is your permanent `WHATSAPP_TOKEN` for your `.env` file. You will not be able to see it again.

### Step 6: Create the OTP Message Template

WhatsApp requires pre-approved templates for business-initiated messages like OTPs. This is done on the **Meta Business Portfolio** website.

1.  From your app's dashboard on the developer site, go to **WhatsApp -> API Setup**. You will see a link that says "To create your own message templates, click here." This is a shortcut that will take you to the correct page on `business.facebook.com`.
2.  Click **Create Template**.
3.  Configure the template as follows:
    -   **Category**: **Authentication**.
    -   **Name**: `otp` (this name must match the one used in the PHP code).
    -   **Languages**: Select your desired language (e.g., `English (US)`).
4.  **Configure the Template Content**:
    -   **Important Note**: For `Authentication` templates, Meta provides a standardized, uneditable message body to prevent phishing. You cannot change the text like "Your verification code is...".
    -   You will only be able to configure the OTP length and expiry time. The PHP code sends a 6-digit code, so ensure your template reflects that.
    -   You can add a "Copy Code" button, which is highly recommended for a better user experience.
5.  **Submit for Approval**:
    -   Click **Submit**. Meta's review is usually very fast for authentication templates.

### Step 7: Add and Verify a Phone Number for Testing

Before sending messages to any number, you must add it to a verified list.

1.  Go back to the **API Setup** page on the **Meta for Developers** site (`developers.facebook.com`).
2.  Scroll down to the "Send and receive messages" section.
3.  Click the "To" field and select **Manage phone number list**.
4.  Add a personal WhatsApp number. Meta will send a verification code to that number on WhatsApp to confirm you own it.
5.  Once verified, this number can receive test messages from your app.

## Running the Application

1.  Place the project folder inside your web server's root directory (e.g., `C:/xampp/htdocs/`).
2.  Start your Apache and MongoDB services.
3.  Open your web browser and navigate to `http://localhost/your-project-folder/index.php`.

You should now see the verification form, ready to send OTPs!
