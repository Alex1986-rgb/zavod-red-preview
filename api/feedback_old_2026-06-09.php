<?php
header('Content-Type: application/json');

// Защита от прямого доступа.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Проверка Honeypot, которую мы вставили.
if (!empty($_POST['work_email'])) {
    // Это бот
    echo json_encode(['status' => 'success']); // Возвращаем success, чтобы бот не заподозрил.
    exit;
}

// Получение данных из формы
$name = isset($_POST['text-562']) ? htmlspecialchars(trim($_POST['text-562'])) : '';
$phone = isset($_POST['tel-535']) ? htmlspecialchars(trim($_POST['tel-535'])) : '';
$email = '';
if (isset($_POST['email-727'])) $email = htmlspecialchars(trim($_POST['email-727']));
elseif (isset($_POST['email-878'])) $email = htmlspecialchars(trim($_POST['email-878']));
elseif (isset($_POST['email-701'])) $email = htmlspecialchars(trim($_POST['email-701']));
elseif (isset($_POST['email-228'])) $email = htmlspecialchars(trim($_POST['email-228']));
elseif (isset($_POST['email-7'])) $email = htmlspecialchars(trim($_POST['email-7']));
elseif (isset($_POST['email-214'])) $email = htmlspecialchars(trim($_POST['email-214']));
elseif (isset($_POST['email-660'])) $email = htmlspecialchars(trim($_POST['email-660']));
elseif (isset($_POST['email-730'])) $email = htmlspecialchars(trim($_POST['email-730']));

$message = isset($_POST['textarea-725']) ? htmlspecialchars(trim($_POST['textarea-725'])) : '';

$productTitle = isset($_POST['product_title']) ? htmlspecialchars(trim($_POST['product_title'])) : '';
$vacancyName = isset($_POST['vacancy-name']) ? htmlspecialchars(trim($_POST['vacancy-name'])) : '';

// Validation
if (empty($name)) {
    echo json_encode(['status' => 'error', 'message' => 'Пожалуйста, заполните обязательное поле: Имя.']);
    exit;
}

if (isset($_POST['tel-535'])) {
    if (empty($phone)) {
        echo json_encode(['status' => 'error', 'message' => 'Пожалуйста, заполните обязательное поле: Телефон.']);
        exit;
    }
    if (strlen($phone) < 18) {
        echo json_encode(['status' => 'error', 'message' => 'Пожалуйста, введите корректный номер телефона.']);
        exit;
    }
} else {
    if (empty($email)) {
        echo json_encode(['status' => 'error', 'message' => 'Пожалуйста, заполните обязательное поле: Email.']);
        exit;
    }
}

// Формирование письма
$to = 'zr@zavod-red.ru';
$subject = 'Новая заявка с сайта zavod-red.ru';

$body = "<h2>Новая заявка с сайта zavod-red.ru</h2>";
$body .= "<p><strong>Имя:</strong> {$name}</p>";
$body .= "<p><strong>Телефон:</strong> {$phone}</p>";
if (!empty($email)) $body .= "<p><strong>Email:</strong> {$email}</p>";
if (!empty($productTitle)) $body .= "<p><strong>Товар:</strong> {$productTitle}</p>";
if (!empty($vacancyName)) $body .= "<p><strong>Вакансия:</strong> {$vacancyName}</p>";
if (!empty($message)) $body .= "<p><strong>Сообщение/Вопрос:</strong><br>{$message}</p>";

// Файлы
$boundary = md5(uniqid(time()));
$headers = "MIME-Version: 1.0\r\n";
$headers .= "From: no-reply@zavod-red.ru\r\n";

if (isset($_FILES['file-174']) && $_FILES['file-174']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath = $_FILES['file-174']['tmp_name'];
    $fileName = $_FILES['file-174']['name'];
    
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
    
    $multipartBody = "--{$boundary}\r\n";
    $multipartBody .= "Content-Type: text/html; charset=UTF-8\r\n";
    $multipartBody .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $multipartBody .= $body . "\r\n";
    
    $fileData = file_get_contents($fileTmpPath);
    $fileData = chunk_split(base64_encode($fileData));
    
    $multipartBody .= "--{$boundary}\r\n";
    $multipartBody .= "Content-Type: application/octet-stream; name=\"{$fileName}\"\r\n";
    $multipartBody .= "Content-Disposition: attachment; filename=\"{$fileName}\"\r\n";
    $multipartBody .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $multipartBody .= $fileData . "\r\n";
    $multipartBody .= "--{$boundary}--";
    
    $body = $multipartBody;
} else {
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
}

// Отправка письма
if (mail($to, $subject, $body, $headers)) {
    echo json_encode(['status' => 'success', 'message' => 'Message has been sent']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Не удалось отправить сообщение.']);
}
