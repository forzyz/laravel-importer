<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ServerMaxUpload implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $max = $this->serverLimitBytes();
        if($value->getSize() >  $max) {
            $fail('File exceeds server max upload size of '. $this->human($max).'.');
        }
    }

    private function serverLimitBytes(): int {
        $toBytes = function($v) {
            $v = trim($v);
            $unit = strtoupper(substr($v, -1));
            $num = (float)$v;
            $pow = ['K' => 1, 'M' => 2, 'G' => 3][$unit] ?? 0;
            return (int)($num * (1024 ** $pow));
        };

        return min($toBytes(ini_get('upload_max_filesize')), $toBytes(ini_get('post_max_size')));
    }

    private function human($b) {
        foreach(['B', 'KB', 'MB', 'GB', 'TB'] as $u) {
            if ($b < 1024) {
                return round($b, 2) . " $u";
            }
            $b /= 1024;
        }
        return round($b, 2) . " PB";
    }
}