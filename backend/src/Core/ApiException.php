<?php
declare(strict_types=1);
namespace App\Core;

/** خطأ قياسي: {error:{code,message,details}} */
class ApiException extends \RuntimeException
{
    public function __construct(public int $status, public string $errorCode, string $message, public array $details = [])
    {
        parent::__construct($message);
    }
}
