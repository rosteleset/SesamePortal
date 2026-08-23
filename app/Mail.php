<?php

declare(strict_types=1);

namespace SesamePortal;

final class Mail
{
    public static function send(string $to, string $subject, string $htmlBody): bool
    {
        $host = (string)DB::setting('smtp_host', (string)Config::get('smtp_host', ''));
        if ($host === '') {
            return false;
        }
        $port = (int)DB::setting('smtp_port', (string)Config::get('smtp_port', 465));
        $user = (string)DB::setting('smtp_user', (string)Config::get('smtp_user', ''));
        $passwordEnc = (string)DB::setting('smtp_password', '');
        $password = $passwordEnc !== ''
            ? Crypto::decrypt($passwordEnc)
            : (string)Config::get('smtp_password', '');
        $security = (string)DB::setting('smtp_security', (string)Config::get('smtp_security', 'ssl'));
        $fromEmail = (string)DB::setting('smtp_from_email', (string)Config::get('smtp_from_email', $user));
        $fromName = (string)DB::setting('smtp_from_name', (string)Config::get('smtp_from_name', 'SesamePortal'));

        $remote = ($security === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            return false;
        }

        $read = function () use ($fp): string {
            $data = '';
            while (!feof($fp)) {
                $line = fgets($fp, 515);
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $write = function (string $cmd) use ($fp): void {
            fwrite($fp, $cmd . "\r\n");
        };

        $read();
        $write('EHLO ' . php_uname('n'));
        $resp = $read();

        if ($security === 'tls') {
            $write('STARTTLS');
            $read();
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $write('EHLO ' . php_uname('n'));
            $resp = $read();
        }

        if ($user !== '') {
            $write('AUTH LOGIN');
            $read();
            $write(base64_encode($user));
            $read();
            $write(base64_encode($password));
            $authResp = $read();
            if (!str_starts_with($authResp, '235')) {
                fclose($fp);
                return false;
            }
        }

        $write('MAIL FROM:<' . $fromEmail . '>');
        $read();
        $write('RCPT TO:<' . $to . '>');
        $read();
        $write('DATA');
        $read();

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . self::encodeHeader($fromName) . ' <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
        ];

        $body = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody . "\r\n.\r\n";
        $write($body);
        $dataResp = $read();
        $write('QUIT');
        fclose($fp);

        return str_starts_with($dataResp, '250');
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }
}
