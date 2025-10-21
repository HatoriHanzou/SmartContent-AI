<?php
/**
 * A simple, dependency-free library to encode and decode JSON Web Tokens (JWT).
 *
 * @author Anant Narayanan <anant@anant.me>
 * @license MIT
 */
class SimpleJWT {

    private static function urlsafeB64Encode($input) {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    private static function urlsafeB64Decode($input) {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }

    public static function encode($payload, $key, $expire = 60) {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        
        // Add issued at, expiration, and issuer claims
        $payload['iat'] = time();
        $payload['exp'] = time() + $expire;
        
        $segments = [];
        $segments[] = self::urlsafeB64Encode(json_encode($header));
        $segments[] = self::urlsafeB64Encode(json_encode($payload));
        
        $signing_input = implode('.', $segments);
        $signature = hash_hmac('sha256', $signing_input, $key, true);
        $segments[] = self::urlsafeB64Encode($signature);
        
        return implode('.', $segments);
    }

    public static function decode($jwt, $key) {
        $tks = explode('.', $jwt);
        if (count($tks) != 3) {
            throw new Exception('Wrong number of segments');
        }
        
        list($headb64, $bodyb64, $cryptob64) = $tks;
        
        if (null === ($header = json_decode(self::urlsafeB64Decode($headb64))) || null === ($payload = json_decode(self::urlsafeB64Decode($bodyb64)))) {
            throw new Exception('Invalid segment encoding');
        }
        
        $sig = self::urlsafeB64Decode($cryptob64);
        if (hash_hmac('sha256', $headb64 . '.' . $bodyb64, $key, true) !== $sig) {
            throw new Exception('Signature verification failed');
        }
        
        if ($payload->exp < time()) {
            throw new Exception('Expired token');
        }
        
        return $payload;
    }
}
?>

