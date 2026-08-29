<?php
if (!defined('Profess0rPay_INIT')) {
    exit('Direct access not allowed');
}

class Profess0rPayLogger {
    public static function log($level, $message, $file, $line) {
        $url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'CLI';
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
        $date = date('Y-m-d H:i:s');
        
        try {
            global $db_host, $db_port, $db_user, $db_pass, $db_name, $db_prefix;
            
            $dsn = "mysql:host=" . ($db_host ?? 'localhost') . ";dbname=" . ($db_name ?? 'profess0rpay_db');
            if (!empty($db_port)) $dsn .= ";port=$db_port";
            $pdo = new PDO($dsn, $db_user ?? 'root', $db_pass ?? '');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $prefix = $db_prefix ?? 'pp_';
            $stmt = $pdo->prepare("INSERT INTO {$prefix}system_logs (error_level, message, file_name, line_number, url, ip_address, created_at) VALUES (:level, :message, :file, :line, :url, :ip, :created)");
            $stmt->execute([
                ':level' => $level,
                ':message' => $message,
                ':file' => $file,
                ':line' => $line,
                ':url' => $url,
                ':ip' => $ip,
                ':created' => $date
            ]);
        } catch (Throwable $e) {
            // DB failed, write to file
            $dbErrMsg = $e->getMessage();
            $logMsg = "[{$date}] [{$level}] {$message} in {$file} on line {$line} (URL: {$url}, IP: {$ip}) - DB Error: {$dbErrMsg}\n";
            file_put_contents(__DIR__ . '/../../error.log', $logMsg, FILE_APPEND);
        }
    }

    public static function handleError($errno, $errstr, $errfile, $errline) {
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $level = 'Notice/Warning';
        if ($errno == E_ERROR || $errno == E_USER_ERROR) {
            $level = 'Fatal Error';
        }

        self::log($level, $errstr, $errfile, $errline);

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'false', 'title' => 'System Error', 'message' => 'An internal error occurred. Please check the system logs.']);
            exit;
        }
        
        return true; 
    }

    public static function handleException($exception) {
        self::log('Exception', $exception->getMessage(), $exception->getFile(), $exception->getLine());

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'false', 'title' => 'System Error', 'message' => 'An internal exception occurred. Please check the system logs.']);
            exit;
        }
    }

    public static function handleShutdown() {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            self::log('Fatal Shutdown Error', $error['message'], $error['file'], $error['line']);
            
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'false', 'title' => 'System Error', 'message' => 'A fatal error occurred. Please check the system logs.']);
            }
        }
    }
}

set_error_handler(['Profess0rPayLogger', 'handleError']);
set_exception_handler(['Profess0rPayLogger', 'handleException']);
register_shutdown_function(['Profess0rPayLogger', 'handleShutdown']);
