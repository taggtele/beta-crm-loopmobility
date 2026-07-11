<?php
/**
 * Core Logger - Monolog-style simple logger
 */

class Logger {
    private static ?Logger $instance = null;
    private string $logDir;
    private string $channel;
    
    private function __construct(string $channel = 'system') {
        $this->logDir = LOGS_PATH;
        $this->channel = $channel;
        
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }
    
    public static function channel(string $channel): Logger {
        $instance = new self($channel);
        return $instance;
    }
    
    public static function getInstance(): Logger {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function info(string $message, array $context = []): void {
        $this->log('INFO', $message, $context);
    }
    
    public function error(string $message, array $context = []): void {
        $this->log('ERROR', $message, $context);
    }
    
    public function warning(string $message, array $context = []): void {
        $this->log('WARNING', $message, $context);
    }
    
    public function debug(string $message, array $context = []): void {
        $this->log('DEBUG', $message, $context);
    }
    
    private function log(string $level, string $message, array $context = []): void {
        $logFile = $this->logDir . '/' . $this->channel . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $contextJson = $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        
        $logLine = "[{$timestamp}] [{$level}] {$message} {$contextJson}" . PHP_EOL;
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
}
