<?php

namespace CorsivoMail\Imap;

if (!defined('ABSPATH')) {
	exit;
}

class Client {

	private $socket;
	private $tag = 0;
	private $timeout;
	private $max_response_bytes;

	public function __construct($timeout = 15) {
		$this->timeout = $timeout;
		$this->max_response_bytes = max(1048576, (int) apply_filters('corsivo_mail_imap_max_response_bytes', 25 * MB_IN_BYTES));
	}

	public function connect($host, $port, $secure = 'ssl') {
		$transport = $secure === 'ssl' ? 'ssl://' : 'tcp://';
		$context = stream_context_create(['ssl' => [
			'verify_peer' => true,
			'verify_peer_name' => true,
			'peer_name' => $host,
			'SNI_enabled' => true,
			'disable_compression' => true,
		]]);

		$this->socket = @stream_socket_client(
			$transport . $host . ':' . (int) $port,
			$errno,
			$errstr,
			$this->timeout,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if (!$this->socket) {
			throw new \Exception('Connessione IMAP fallita: ' . ($errstr ?: 'host non raggiungibile'));
		}

		stream_set_timeout($this->socket, $this->timeout);
		$greeting = $this->read_response();
		if (!preg_match('/^\*\s+OK\b/i', $greeting)) {
			$this->logout();
			throw new \RuntimeException('Il server IMAP non ha accettato la connessione.');
		}

		if ($secure === 'tls') {
			$this->command('STARTTLS');
			$method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
			if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
				$method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
			}
			if (stream_socket_enable_crypto($this->socket, true, $method) !== true) {
				fclose($this->socket);
				$this->socket = null;
				throw new \RuntimeException('Negoziazione STARTTLS IMAP fallita.');
			}
		}
	}

	public function login($user, $password) {
		$this->command('LOGIN ' . $this->quote($user) . ' ' . $this->quote($password));
	}

	public function select($mailbox = 'INBOX') {
		$response = $this->command('SELECT ' . $this->quote($mailbox));

		$info = ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0];
		if (preg_match('/\*\s+(\d+)\s+EXISTS/i', $response, $m)) {
			$info['exists'] = (int) $m[1];
		}
		if (preg_match('/\[UIDVALIDITY\s+(\d+)\]/i', $response, $m)) {
			$info['uidvalidity'] = (int) $m[1];
		}
		if (preg_match('/\[UIDNEXT\s+(\d+)\]/i', $response, $m)) {
			$info['uidnext'] = (int) $m[1];
		}
		return $info;
	}

	public function uid_search($criteria = 'ALL') {
		$response = $this->command('UID SEARCH ' . $criteria);
		if (preg_match('/\*\s+SEARCH([^\r\n]*)/i', $response, $m)) {
			return array_map('intval', preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY));
		}
		return [];
	}

	public function fetch_headers($uids) {
		if (!$uids) {
			return [];
		}

		$set      = implode(',', array_map('intval', $uids));
		$fields   = 'FROM TO CC SUBJECT DATE MESSAGE-ID IN-REPLY-TO REFERENCES';
		$response = $this->command('UID FETCH ' . $set . ' (UID FLAGS INTERNALDATE BODY.PEEK[HEADER.FIELDS (' . $fields . ')])');

		return $this->parse_fetch($response);
	}

	public function fetch_body($uid) {
		$response = $this->command('UID FETCH ' . (int) $uid . ' (BODY.PEEK[])');
		$parsed   = $this->parse_fetch($response);
		return $parsed ? reset($parsed)['raw'] : '';
	}

	public function uid_store($uid, $flags) {
		$this->command('UID STORE ' . (int) $uid . ' +FLAGS.SILENT (' . $flags . ')');
	}

	public function uid_remove_flags($uid, $flags) {
		$this->command('UID STORE ' . (int) $uid . ' -FLAGS.SILENT (' . $flags . ')');
	}

	public function capabilities() {
		$response = $this->command('CAPABILITY');
		if (!preg_match('/^\*\s+CAPABILITY\s+(.+)$/mi', $response, $match)) {
			return [];
		}
		return array_map('strtoupper', preg_split('/\s+/', trim($match[1]), -1, PREG_SPLIT_NO_EMPTY));
	}

	public function delete_uid($uid) {
		if (!in_array('UIDPLUS', $this->capabilities(), true)) {
			throw new \RuntimeException('Il server IMAP non supporta la cancellazione UID sicura.');
		}
		$this->uid_store($uid, '\\Deleted');
		try {
			$this->command('UID EXPUNGE ' . (int) $uid);
		} catch (\Throwable $error) {
			$this->uid_remove_flags($uid, '\\Deleted');
			throw $error;
		}
	}

	public function logout() {
		if ($this->socket) {
			try {
				$this->command('LOGOUT');
			} catch (\Throwable $error) {
			} finally {
				fclose($this->socket);
				$this->socket = null;
			}
		}
	}

	private function parse_fetch($response) {
		$messages = [];
		$offset   = 0;

		while (preg_match('/\*\s+(\d+)\s+FETCH\s+\(/i', $response, $m, PREG_OFFSET_CAPTURE, $offset)) {
			$start = $m[0][1] + strlen($m[0][0]);
			$seq   = (int) $m[1][0];

			$message = ['seq' => $seq, 'uid' => 0, 'flags' => [], 'internaldate' => '', 'raw' => ''];

			if (preg_match('/UID\s+(\d+)/i', $response, $um, 0, $start)) {
				$message['uid'] = (int) $um[1];
			}
			if (preg_match('/FLAGS\s+\(([^)]*)\)/i', $response, $fm, 0, $start)) {
				$message['flags'] = preg_split('/\s+/', trim($fm[1]), -1, PREG_SPLIT_NO_EMPTY);
			}
			if (preg_match('/INTERNALDATE\s+"([^"]+)"/i', $response, $dm, 0, $start)) {
				$message['internaldate'] = $dm[1];
			}

			if (preg_match('/\{(\d+)\}\r\n/', $response, $lm, PREG_OFFSET_CAPTURE, $start)) {
				$literal_start         = $lm[0][1] + strlen($lm[0][0]);
				$literal_len           = (int) $lm[1][0];
				$message['raw']        = substr($response, $literal_start, $literal_len);
				$message['headers']    = parse_headers($message['raw']);
				$offset                = $literal_start + $literal_len;
			} else {
				$offset = $start;
			}

			$messages[$message['uid'] ?: $seq] = $message;
		}

		return $messages;
	}

	private function command($command) {
		$this->tag++;
		$label = 'A' . str_pad($this->tag, 4, '0', STR_PAD_LEFT);
		if (fwrite($this->socket, $label . ' ' . $command . "\r\n") === false) {
			throw new \RuntimeException('Scrittura sulla connessione IMAP fallita.');
		}
		return $this->read_response($label);
	}

	private function read_response($label = null) {
		$data = '';

		while (true) {
			$line = fgets($this->socket);
			if ($line === false) {
				$meta = stream_get_meta_data($this->socket);
				throw new \Exception($meta['timed_out'] ? 'Timeout IMAP' : 'Connessione IMAP interrotta');
			}
			$data .= $line;
			if (strlen($data) > $this->max_response_bytes) {
				throw new \RuntimeException('Risposta IMAP oltre il limite consentito.');
			}

			if (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
				$need = (int) $m[1];
				if ($need > $this->max_response_bytes || strlen($data) + $need > $this->max_response_bytes) {
					throw new \RuntimeException('Messaggio IMAP oltre il limite consentito.');
				}
				$got  = 0;
				while ($got < $need) {
					$chunk = fread($this->socket, $need - $got);
					if ($chunk === false || $chunk === '') {
						throw new \RuntimeException('Risposta IMAP interrotta durante il trasferimento del messaggio.');
					}
					$data .= $chunk;
					$got  += strlen($chunk);
				}
				continue;
			}

			if ($label === null) {
				break;
			}

			if (preg_match('/^' . preg_quote($label, '/') . '\s+(OK|NO|BAD)\b(.*)$/im', $line, $m)) {
				if (strtoupper($m[1]) !== 'OK') {
					throw new \Exception('IMAP ' . $m[1] . ':' . rtrim($m[2]));
				}
				break;
			}
		}

		return $data;
	}

	private function quote($string) {
		return '"' . addcslashes($string, '"\\') . '"';
	}
}

function parse_headers($raw) {
	$headers = [];
	$lines   = preg_split('/\r\n(?![ \t])/', trim($raw));

	foreach ($lines as $line) {
		if (strpos($line, ':') === false) {
			continue;
		}
		list($key, $value) = explode(':', $line, 2);
		$headers[strtolower(trim($key))] = decode_mime(trim(preg_replace('/\r\n[ \t]+/', ' ', $value)));
	}

	return $headers;
}

function decode_mime($string) {
	if (strpos($string, '=?') === false) {
		return $string;
	}
	$decoded = iconv_mime_decode($string, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
	return $decoded !== false ? $decoded : $string;
}
