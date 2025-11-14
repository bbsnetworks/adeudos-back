<?php
function body_json(): array {
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = [];
return $data;
}


function req_int($arr, $key): int { return (int)($arr[$key] ?? 0); }
function req_num($arr, $key): float { return (float)($arr[$key] ?? 0); }
function req_str($arr, $key): string { return trim((string)($arr[$key] ?? '')); }


function assert_between($val, $min, $max, $msg) {
if ($val < $min || $val > $max) fail($msg, 422);
}
function assert_positive($val, $msg) {
if (!is_numeric($val) || $val <= 0) fail($msg, 422);
}
function assert_nonnegative($val, $msg) {
if (!is_numeric($val) || $val < 0) fail($msg, 422);
}