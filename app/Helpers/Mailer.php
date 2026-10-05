<?php

namespace App\Helpers;

use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Mailer
 * Sends a plain-text email with attachments through the SMTP server named in the environment file:
 *   MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_ENCRYPTION (tls | ssl | none),
 *   MAIL_FROM_ADDRESS, MAIL_FROM_NAME (defaults to APP_NAME).
 */
final class Mailer {

	private const ENCRYPTION_MODES = ['tls' => PHPMailer::ENCRYPTION_STARTTLS, 'ssl' => PHPMailer::ENCRYPTION_SMTPS, 'none' => ''];

	/** Seconds to wait on the SMTP server before giving up, so a dead server cannot hang the request. */
	private const SMTP_TIMEOUT_SECONDS = 20;

	public static function isConfigured(): bool {
		return trim((string) Env::get('MAIL_HOST', '')) !== '' && trim((string) Env::get('MAIL_FROM_ADDRESS', '')) !== '';
	}

	/**
	 * @param string[]                                  $recipientEmails
	 * @param array{address: string, name: string}|null $replyTo     Where the recipients' replies go.
	 * @param array<string, string>                     $attachments File contents keyed by filename.
	 * @throws \RuntimeException When mail is not set up, or the SMTP server refuses the message.
	 */
	public static function sendPlainText(array $recipientEmails, string $subject, string $body, ?array $replyTo, array $attachments): void {
		if (!self::isConfigured()) {
			throw new \RuntimeException('Mail is not configured: set MAIL_HOST and MAIL_FROM_ADDRESS.');
		}

		$encryptionSetting = strtolower(trim((string) Env::get('MAIL_ENCRYPTION', 'tls')));
		if (!array_key_exists($encryptionSetting, self::ENCRYPTION_MODES)) {
			throw new \RuntimeException('MAIL_ENCRYPTION must be one of: ' . implode(', ', array_keys(self::ENCRYPTION_MODES)) . '.');
		}

		$message = new PHPMailer(true);
		try {
			$message->isSMTP();
			$message->Host        = (string) Env::get('MAIL_HOST');
			$message->Port        = (int) Env::get('MAIL_PORT', '587');
			$message->SMTPSecure  = self::ENCRYPTION_MODES[$encryptionSetting];
			$message->SMTPAutoTLS = $encryptionSetting !== 'none';
			$message->Timeout     = self::SMTP_TIMEOUT_SECONDS;
			$message->CharSet     = PHPMailer::CHARSET_UTF8;

			$smtpUsername = (string) Env::get('MAIL_USERNAME', '');
			if ($smtpUsername !== '') {
				$message->SMTPAuth = true;
				$message->Username = $smtpUsername;
				$message->Password = (string) Env::get('MAIL_PASSWORD', '');
			}

			$message->setFrom((string) Env::get('MAIL_FROM_ADDRESS'), (string) Env::get('MAIL_FROM_NAME', Env::get('APP_NAME', '')));
			if ($replyTo !== null) {
				$message->addReplyTo($replyTo['address'], $replyTo['name']);
			}
			foreach ($recipientEmails as $recipientEmail) {
				$message->addAddress($recipientEmail);
			}

			$message->isHTML(false);
			$message->Subject = $subject;
			$message->Body    = $body;
			foreach ($attachments as $attachmentFilename => $attachmentContents) {
				$message->addStringAttachment($attachmentContents, $attachmentFilename);
			}

			$message->send();
		} catch (PhpMailerException $sendError) {
			throw new \RuntimeException('The email could not be sent: ' . $sendError->getMessage(), 0, $sendError);
		}
	}
}
