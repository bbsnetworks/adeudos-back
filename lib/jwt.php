<?php
function b64url($d){ return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function jwt_sign(array $payload, string $secret): string {
  $h = b64url(json_encode(['alg'=>'HS256','typ'=>'JWT']));
  $p = b64url(json_encode($payload));
  $s = b64url(hash_hmac('sha256', "$h.$p", $secret, true));
  return "$h.$p.$s";
}
function jwt_verify(string $jwt, string $secret): ?array {
  $parts = explode('.', $jwt);
  if (count($parts)!==3) return null;
  [$h,$p,$s] = $parts;
  $calc = b64url(hash_hmac('sha256', "$h.$p", $secret, true));
  if (!hash_equals($calc, $s)) return null;
  $payload = json_decode(base64_decode(strtr($p,'-_','+/')), true);
  if (!$payload) return null;
  if (isset($payload['exp']) && time() >= $payload['exp']) return null;
  return $payload;
}
