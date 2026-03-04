<?php

declare(strict_types=1);

namespace futuretek\openapi\Middleware;

/**
 * Default logger using Yii2 logging.
 *
 * Falls back to error_log if Yii is not available.
 */
class DefaultLogger implements LoggerInterface
{
    public function __construct(
        private readonly string $category = 'api',
    ) {}

    public function info(string $message, array $context = []): void
    {
        if (class_exists('\Yii')) {
            \Yii::info($this->formatMessage($message, $context), $this->category);
        }
    }

    public function warning(string $message, array $context = []): void
    {
        if (class_exists('\Yii')) {
            \Yii::warning($this->formatMessage($message, $context), $this->category);
        }
    }

    public function error(string $message, array $context = []): void
    {
        if (class_exists('\Yii')) {
            \Yii::error($this->formatMessage($message, $context), $this->category);
        } else {
            error_log($this->formatMessage($message, $context));
        }
    }

    private function formatMessage(string $message, array $context): string
    {
        if (empty($context)) {
            return $message;
        }

        return $message . ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

