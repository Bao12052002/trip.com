<?php

class TripHelper {

    /**

     * Mã hóa body theo chuẩn Base64 để gửi đi.

     * Logic này giữ nguyên vì chúng ta chưa biết server Trip.com thực sự mong đợi gì khi NHẬN.

     */

    public static function encryptBody($plainText, $aesKey, $aesIv) {
        // aesKey và aesIv chính xác 16 ký tự (bytes) do Trip cung cấp
        $key = $aesKey; 
        $iv  = $aesIv;

        $encrypted = openssl_encrypt($plainText, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            error_log("ERROR: Encryption failed - " . openssl_error_string());
            return false;
        }
        // Encode thành custom-hex 'a'–'p' theo docs
        $bodyToSend = self::customEncodeBytes($encrypted);

        error_log("PLAIN JSON:      $plainText");
        error_log("ENCRYPTED (hex): $bodyToSend");

        return $bodyToSend;
    }

    public static function safeEncryptBody(string $plainText, string $aesKey, string $aesIv) {
        // Gọi encryptBody, nếu false thì trả về false để ApiController bắt
        $encrypted = self::encryptBody($plainText, $aesKey, $aesIv);
        return $encrypted !== false ? $encrypted : false;
    }



    private static function customEncodeBytes($data) {

        $result = '';

        for ($i = 0; $i < strlen($data); $i++) {

            $byte = ord($data[$i]);

            $c1 = chr(ord('a') + (($byte >> 4) & 0x0F));

            $c2 = chr(ord('a') + ($byte & 0x0F));

            $result .= $c1 . $c2;

        }

        return $result;

    }



    /**

     * Giải mã body nhận được từ Trip.com.

     * ĐÃ SỬA LẠI để thử nghiệm logic giải mã theo code Java trong tài liệu.

     */

    public static function decryptBody($cipherText, $aesKey, $aesIv) {
        $key = $aesKey;
        $iv  = $aesIv;

        // Giải mã custom-hex trước
        $decoded = self::decodeBytesCustom($cipherText);
        $decrypted = openssl_decrypt($decoded, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            error_log("ERROR: Decrypt failed - " . openssl_error_string());
            return false;
        }
        return $decrypted;
    }



    /**

     * Hàm giải mã 'tự chế' được viết lại từ code Java trong tài liệu.

     */

    private static function decodeBytesCustom($str) {

        if (strlen($str) % 2 !== 0) {

            throw new Exception("Invalid custom hex string - length must be even.");

        }

        

        $bytes = '';

        for ($i = 0; $i < strlen($str); $i += 2) {

            $c1 = $str[$i];

            $c2 = $str[$i + 1];

            

            // Validate characters are in 'a-p' range

            if (ord($c1) < ord('a') || ord($c1) > ord('p') || 

                ord($c2) < ord('a') || ord($c2) > ord('p')) {

                throw new Exception("Invalid custom hex character at position $i");

            }

            

            $byteVal = (ord($c1) - ord('a')) << 4;

            $byteVal += (ord($c2) - ord('a'));

            $bytes .= chr($byteVal);

        }

        return $bytes;

    }



    /**

     * Hàm tạo chữ ký, giữ nguyên.

     */

    public static function generateSign($header, $bodyEncrypted, $signKey) {

        $accountId = $header['accountId'] ?? '';

        $serviceName = $header['serviceName'] ?? '';

        $requestTime = $header['requestTime'] ?? '';

        $version = $header['version'] ?? '';



        // Tài liệu nói chữ ký là md5 của các trường nối với nhau

        $raw = $accountId . $serviceName . $requestTime . $bodyEncrypted . $version . $signKey;

        

        // Add logging for debugging

        error_log("SIGN RAW STRING: " . $raw);

        $signature = md5($raw);

        error_log("SIGN GENERATED: " . $signature);

        

        return $signature;

    }

    

    public static function verifySignature($header, $bodyEncrypted, $signKey) {

        $expected = self::generateSign($header, $bodyEncrypted, $signKey);

        return isset($header['sign']) && strtolower($header['sign']) === strtolower($expected);

    }

}

?>