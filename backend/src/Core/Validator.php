<?php
declare(strict_types=1);
namespace App\Core;

/** تحقق بسيط: قواعد نصية "required|string|max:200|in:a,b|int|min:1|datetime|lat|lon|nullable" */
final class Validator
{
    public static function validate(array $input, array $rules): array
    {
        $errors = []; $out = [];
        foreach ($rules as $field => $ruleStr) {
            $rs = explode('|', $ruleStr);
            $val = $input[$field] ?? null;
            $nullable = in_array('nullable', $rs, true);
            $empty = $val === null || $val === '';
            if ($empty) {
                if (in_array('required', $rs, true)) { $errors[$field] = 'الحقل مطلوب'; }
                elseif (array_key_exists($field, $input)) { $out[$field] = null; }
                continue;
            }
            foreach ($rs as $r) {
                [$name, $arg] = array_pad(explode(':', $r, 2), 2, null);
                $err = match ($name) {
                    'string' => is_scalar($val) ? null : 'يجب أن يكون نصًا',
                    'max' => mb_strlen((string)$val) > (int)$arg ? "الحد الأقصى $arg حرفًا" : null,
                    'min' => is_numeric($val) && (float)$val < (float)$arg ? "الحد الأدنى $arg" : null,
                    'int' => filter_var($val, FILTER_VALIDATE_INT) === false ? 'يجب أن يكون عددًا صحيحًا' : null,
                    'num' => is_numeric($val) ? null : 'يجب أن يكون رقمًا',
                    'bool' => is_bool($val) || in_array($val, [0, 1, '0', '1', 'true', 'false'], true) ? null : 'قيمة منطقية غير صالحة',
                    'in' => in_array((string)$val, explode(',', (string)$arg), true) ? null : 'قيمة غير مسموحة',
                    'lat' => is_numeric($val) && abs((float)$val) <= 90 ? null : 'خط عرض غير صالح',
                    'lon' => is_numeric($val) && abs((float)$val) <= 180 ? null : 'خط طول غير صالح',
                    'datetime' => strtotime(str_contains((string)$val, 'T') ? (string)$val : $val . ' UTC') !== false ? null : 'تاريخ/وقت غير صالح',
                    'array' => is_array($val) ? null : 'يجب أن يكون مصفوفة',
                    default => null,
                };
                if ($err) { $errors[$field] = $err; break; }
            }
            $out[$field] = $val;
        }
        if ($errors) { throw new ApiException(422, 'validation_failed', 'بيانات غير صالحة', $errors); }
        return $out;
    }
}
