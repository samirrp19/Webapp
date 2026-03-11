<?php
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.html");
    exit();
}

function clean_input($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

$name = isset($_POST['name']) ? clean_input($_POST['name']) : '';
$email = isset($_POST['email']) ? clean_input($_POST['email']) : '';
$phone = isset($_POST['phone']) ? clean_input($_POST['phone']) : '';
$message = isset($_POST['message']) ? clean_input($_POST['message']) : '';

$errors = [];

if (empty($name)) {
    $errors[] = "Name is required.";
}

if (empty($email)) {
    $errors[] = "Email is required.";
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Please enter a valid email address.";
}

if (empty($message)) {
    $errors[] = "Message is required.";
}

$mailSent = false;
$mailError = "";

if (empty($errors)) {
    $mail = new PHPMailer(true);

    try {
        // SMTP settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'samir.rp19@gmail.com';          // your Gmail
        $mail->Password   = 'jbnrcpfwcbctzsrs';     // Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Sender and receiver
        $mail->setFrom('samir.rp19@gmail.com', 'Inlustris Website');
        $mail->addAddress('samir.rp19@gmail.com', 'Samir');
        $mail->addReplyTo($email, $name);

        // Email content
        $mail->isHTML(true);
        $mail->Subject = 'New Contact Form Submission - Inlustris';

        $mailBody = "
            <h2>New Contact Form Submission</h2>
            <p><strong>Name:</strong> {$name}</p>
            <p><strong>Email:</strong> {$email}</p>
            <p><strong>Phone:</strong> " . (!empty($phone) ? $phone : 'Not provided') . "</p>
            <p><strong>Message:</strong><br>" . nl2br($message) . "</p>
        ";

        $mail->Body = $mailBody;

        $mail->AltBody = "New Contact Form Submission\n\n"
            . "Name: {$name}\n"
            . "Email: {$email}\n"
            . "Phone: " . (!empty($phone) ? $phone : 'Not provided') . "\n"
            . "Message: {$message}\n";

        $mail->send();
        $mailSent = true;

    } catch (Exception $e) {
        $mailError = $mail->ErrorInfo;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Result</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .result-box {
            max-width: 700px;
            margin: 80px auto;
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.06);
        }
        .error-list {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .success-box {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .submitted-data p {
            margin-bottom: 10px;
            color: #374151;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            text-decoration: none;
            background: #2563eb;
            color: #fff;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 700;
        }
        .back-link:hover {
            background: #1d4ed8;
        }
    </style>
</head>
<body>

<div class="result-box">
    <h2 style="margin-bottom: 20px;">Contact Form Result</h2>

    <?php if (!empty($errors)): ?>
        <div class="error-list">
            <strong>Please fix the following:</strong>
            <ul style="margin-top: 10px; padding-left: 20px;">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo $error; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

    <?php elseif ($mailSent): ?>
        <div class="success-box">
            Thank you, <?php echo $name; ?>. Your message has been submitted successfully and email has been sent.
        </div>

        <div class="submitted-data">
            <p><strong>Name:</strong> <?php echo $name; ?></p>
            <p><strong>Email:</strong> <?php echo $email; ?></p>
            <p><strong>Phone:</strong> <?php echo $phone ? $phone : 'Not provided'; ?></p>
            <p><strong>Message:</strong> <?php echo nl2br($message); ?></p>
        </div>

    <?php else: ?>
        <div class="error-list">
            <strong>Email sending failed.</strong>
            <p style="margin-top:10px;"><?php echo htmlspecialchars($mailError); ?></p>
        </div>
    <?php endif; ?>

    <a class="back-link" href="index.html">Back to Home</a>
</div>

</body>
</html>
