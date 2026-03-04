<?php

use PHPMailer\PHPMailer\PHPMailer;

include __DIR__ . '/phpmailer/PHPMailer.php';
include __DIR__ . '/phpmailer/Exception.php';
include __DIR__ . '/phpmailer/SMTP.php';

class Response
{
    private $email_pattern = '/^[\w.\-+]+@[\w.\-]+\.[a-z]{2,}$/i';
    private $emails_pattern = '/[\w.\-+]+@[\w.\-]+\.[a-z]{2,}/i';
    private $spf_include = 'include:_spf.yoursite.io';
    private $fields;
    private $filenames = [];
    private $form_data;
    private $form_id;
    private $forms_data;
    private $language;
    private $mail;
    private $mailer_error;
    private $referer;

    public function handle(): void
    {
        $this->checkHost();

        $this->form_id = (int) ($_POST['id'] ?? 0);
        $this->language = $_POST['language'] ?? '';

        $forms_json = file_get_contents(__DIR__ . '/../data/forms.json');

        $this->forms_data = json_decode($forms_json);

        $this->form_data = $this->forms_data->forms->{$this->form_id} ?? null;

        $this->checkFields();

        $this->checkSpamProtections();

        $this->setFields();

        $this->referer = $_SERVER['HTTP_REFERER'] ?? '';

        if ($this->referer) {
            $referer = parse_url($this->referer);
            $referer_path = $referer['path'] ?? '';

            if (!preg_match('/^\/preview(\/|$)/i', $referer_path)) {
                // remove query string from referer
                $this->referer =
                    $referer['scheme'] .
                    '://' .
                    $referer['host'] .
                    $referer_path;
            }
        }

        $status = 'success';
        $feedback = [];

        // save
        if (!empty($this->form_data->responses->save) && !$this->save()) {
            $status = 'error';
            $feedback[] = 'Response save failed.';
        }

        // post to url
        if (!empty($this->form_data->responses->url) && !$this->postToUrl()) {
            $status = 'error';
            $feedback[] = 'Response forward to URL failed.';
        }

        // send admin mail
        $admin_mail = $this->getAdminMail();

        if (
            $admin_mail &&
            !$this->sendMail(
                $admin_mail,
                $this->forms_data->language ?? $this->language,
            )
        ) {
            $status = 'error';
            $feedback[] = 'Admin email failed.';

            if ($this->mailer_error) {
                $feedback[] = $this->mailer_error;
            }
        }

        // send confirmation mail
        $confirmation_mail = $this->getConfirmationMail();

        if (
            $confirmation_mail &&
            !$this->sendMail($confirmation_mail, $this->language)
        ) {
            $status = 'error';
            $feedback[] = 'Confirmation email failed.';

            if ($this->mailer_error) {
                $feedback[] = $this->mailer_error;
            }
        }

        // echo feedback
        if ($status == 'success') {
            if (
                $this->language &&
                isset(
                    $this->form_data->feedback->languages->{$this->language}
                        ->success,
                )
            ) {
                $feedback =
                    $this->form_data->feedback->languages->{$this->language}
                        ->success;
            } else {
                $feedback = $this->form_data->feedback->success ?? '';
            }
        }

        echo json_encode(compact('status', 'feedback'));
    }

    private function checkHost(): void
    {
        $http_host = $_SERVER['HTTP_HOST'] ?? '';

        $http_referer = empty($_SERVER['HTTP_REFERER'])
            ? ''
            : parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);

        if (!$http_host || $http_host != $http_referer) {
            echo json_encode([
                'status' => 'error',
                'feedback' => 'HTTP host does not match HTTP referer.',
            ]);
            exit();
        }
    }

    private function checkFields(): void
    {
        $error = false;
        $error_fields = [];

        if (isset($this->form_data->validation)) {
            foreach ($this->form_data->validation as $name => $type) {
                $value = $_POST[$name] ?? ($_FILES[$name]['name'] ?? '');

                if (
                    (is_string($value) && trim($value) === '') ||
                    (is_array($value) && !$value)
                ) {
                    $error = true;
                    $error_fields[] = $name;
                } else {
                    switch ($type) {
                        case 'email':
                            if (!preg_match($this->email_pattern, $value)) {
                                $error = true;
                                $error_fields[] = $name;
                            }
                    }
                }
            }
        }

        if (!$error && $_FILES) {
            foreach ($_FILES as $name => $value) {
                if (empty($value['name'])) {
                    unset($_FILES[$name]);
                } elseif ($value['size'] > 10 * 1024 * 1024) {
                    $error = true;
                    $error_fields[] = $name;
                }
            }
        }

        if ($error) {
            if (
                $this->language &&
                isset(
                    $this->form_data->feedback->languages->{$this->language}
                        ->error,
                )
            ) {
                $feedback =
                    $this->form_data->feedback->languages->{$this->language}
                        ->error;
            } else {
                $feedback = $this->form_data->feedback->error ?? '';
            }

            echo json_encode([
                'status' => 'error',
                'error_fields' => $error_fields,
                'feedback' => $feedback,
            ]);
            exit();
        }
    }

    private function checkSpamProtections(): void
    {
        $spam_protections = $this->form_data->spam_protection ?? [];
        $spam_protections = $spam_protections ?: ['recaptcha_v3'];

        foreach ($spam_protections as $spam_protection) {
            switch ($spam_protection) {
                case 'recaptcha_v3':
                case 'recaptcha_v2_invisible':
                case 'recaptcha_v2_checkbox':
                    // check recaptcha
                    if (!isset($_POST['g-recaptcha-response'])) {
                        echo json_encode([
                            'status' => 'error',
                            'feedback' => 'Recaptcha has not been sent.',
                        ]);
                        exit();
                    }

                    $recaptcha_secret = match ($spam_protection) {
                        'recaptcha_v3' => '6LdVpyEaAAAAAB9CQlADj6uUgbNFIYuhJmFw4N4U',
                        'recaptcha_v2_invisible'
                            => '6Lci2GUUAAAAAL63Glu90drE8doL-JWeA9Ssi6Oy',
                        'recaptcha_v2_checkbox'
                            => '6Lcj8tMZAAAAACnWEX5gD8QZpvpGQ4ggCWU_xCYr',
                        default => '',
                    };

                    $recaptcha_post = [
                        'secret' => $recaptcha_secret,
                        'response' => $_POST['g-recaptcha-response'],
                    ];

                    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');

                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt(
                        $ch,
                        CURLOPT_POSTFIELDS,
                        http_build_query($recaptcha_post),
                    );
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                    $recaptcha_response = curl_exec($ch);

                    if (
                        $recaptcha_response === false ||
                        curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200
                    ) {
                        $recaptcha_response = '[]';
                    }

                    $recaptcha_response =
                        json_decode($recaptcha_response) ?? [];
                    $recaptcha_feedback = '';

                    if (!$recaptcha_response) {
                        $recaptcha_feedback = 'Recaptcha did not respond.';
                    } elseif (!$recaptcha_response->success) {
                        $recaptcha_feedback = 'Recaptcha did not succeed.';
                    } elseif (
                        $recaptcha_response->hostname != $_SERVER['HTTP_HOST']
                    ) {
                        $recaptcha_feedback =
                            'Recaptcha hostname did not match.';
                    } elseif (
                        isset($recaptcha_response->score) &&
                        $recaptcha_response->score < 0.7
                    ) {
                        $recaptcha_feedback = "Recaptcha score ({$recaptcha_response->score}) is to low.";
                    }

                    if ($recaptcha_feedback) {
                        echo json_encode([
                            'status' => 'error',
                            'feedback' => $recaptcha_feedback,
                        ]);
                        exit();
                    }
                    break;
                case 'honeypot':
                    if (!empty($_POST['field_0'])) {
                        echo json_encode([
                            'status' => 'error',
                            'feedback' => 'Honeypot is not empty.',
                        ]);
                        exit();
                    }
            }
        }
    }

    private function setFields(): void
    {
        $this->fields =
            $this->form_data->fields->languages->{$this->language} ??
            ($this->form_data->fields ?? (object) []);

        $field_names = array_keys((array) $this->fields);

        $reserved_names = ['id', 'language', 'field_0', 'g-recaptcha-response'];

        foreach ($_POST + $_FILES as $name => $value) {
            if (
                !in_array($name, $field_names) &&
                !in_array($name, $reserved_names)
            ) {
                $this->fields->$name = '';
            }
        }
    }

    private function save(): bool
    {
        $post = [
            'website_id' => $this->forms_data->website_id,
            'form_id' => $this->form_id,
            'form_name' => $this->form_data->responses->form_name ?? '',
            'url' => $this->referer,
        ];

        $language = $this->forms_data->language ?? $this->language;

        foreach ($this->fields as $name => $field) {
            $value = $_POST[$name] ?? ($_FILES[$name]['name'] ?? '');

            $post["fields[{$name}][label]"] = is_string($field)
                ? $field
                : $field->label->$language ?? ($field->label->default ?? '');

            if ($value) {
                if (is_array($value)) {
                    foreach ($value as $sub_key => $sub_value) {
                        $post[
                            "fields[{$name}][value][{$sub_key}]"
                        ] = $sub_value;
                    }
                } else {
                    $post["fields[{$name}][value]"] = $value;
                }
            }

            $file = $_FILES[$name] ?? [];

            if ($file) {
                $post[$name] = curl_file_create(
                    $file['tmp_name'],
                    $file['type'],
                    $file['name'],
                );
            }
        }

        $ch = curl_init('' . '/api/forms/response');

        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apiKey: ' . '']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        return curl_exec($ch) !== false &&
            curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200;
    }

    private function postToUrl(): bool
    {
        $post = [];

        foreach ($this->fields as $name => $field) {
            $value = $_POST[$name] ?? ($_FILES[$name]['name'] ?? '');

            if ($value) {
                if (is_array($value)) {
                    foreach ($value as $sub_key => $sub_value) {
                        $post["{$name}[{$sub_key}]"] = $sub_value;
                    }
                } else {
                    $post[$name] = $value;
                }
            }

            $file = $_FILES[$name] ?? [];

            if ($file) {
                $post[$name] = curl_file_create(
                    $file['tmp_name'],
                    $file['type'],
                    $file['name'],
                );
            }
        }

        $ch = curl_init($this->form_data->responses->url);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_REFERER, $this->referer);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        return curl_exec($ch) !== false &&
            curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200;
    }

    private function getAdminMail(): ?object
    {
        $admin_mail = $this->form_data->mail ?? null;

        if (
            $admin_mail &&
            isset($admin_mail->from_name) &&
            (empty($admin_mail->from_email) ||
                preg_match($this->email_pattern, $admin_mail->from_email)) &&
            isset($admin_mail->to_email) &&
            preg_match_all(
                $this->emails_pattern,
                $admin_mail->to_email,
                $to_email_matches,
            )
        ) {
            $admin_mail->to_email = $to_email_matches[0];

            unset($admin_mail->reply_to_email);

            if (
                isset($admin_mail->reply_to_email_field) &&
                isset($_POST[$admin_mail->reply_to_email_field])
            ) {
                $reply_to_email = trim(
                    $_POST[$admin_mail->reply_to_email_field],
                );

                if (preg_match($this->email_pattern, $reply_to_email)) {
                    $admin_mail->reply_to_email = $reply_to_email;
                }
            }

            return $admin_mail;
        }

        return null;
    }

    private function getConfirmationMail(): ?object
    {
        $confirmation_mail = $this->form_data->confirmation_mail ?? null;

        if (
            $confirmation_mail &&
            isset($confirmation_mail->from_name) &&
            (empty($confirmation_mail->from_email) ||
                preg_match(
                    $this->email_pattern,
                    $confirmation_mail->from_email,
                )) &&
            isset($confirmation_mail->to_email_field) &&
            isset($_POST[$confirmation_mail->to_email_field])
        ) {
            $to_email = trim($_POST[$confirmation_mail->to_email_field]);

            if (preg_match($this->email_pattern, $to_email)) {
                $confirmation_mail->to_email = [$to_email];

                if (isset($confirmation_mail->reply_to_email)) {
                    if (
                        preg_match(
                            $this->email_pattern,
                            $confirmation_mail->reply_to_email,
                        )
                    ) {
                        $confirmation_mail->reply_to_name =
                            $confirmation_mail->from_name;
                    } else {
                        unset($confirmation_mail->reply_to_email);
                    }
                }

                return $confirmation_mail;
            }
        }

        return null;
    }

    private function sendMail(object $mail, string $language): bool
    {
        $this->setMail($language);

        $sending_method = $this->form_data->mail->sending_method ?? 'server';
        $smtp = $this->form_data->smtp ?? null;

        $from_email = $mail->from_email ?? '';
        $subject =
            $mail->languages->$language->subject ?? ($mail->subject ?? '');
        $message =
            $mail->languages->$language->message ?? ($mail->message ?? '');
        $message_plain = $this->mail->message->plain;
        $message_html = $this->mail->message->html;

        if ($message) {
            $message_plain = <<<TEXT
            {$message}

            ---

            {$this->mail->message->plain}
            TEXT;

            $message_html = nl2br(htmlentities($message), false);

            $message_html = <<<HTML
            <p>{$message_html}</p>
            <hr>
            {$this->mail->message->html}
            HTML;
        }

        if (!$from_email) {
            if (
                $sending_method == 'server' &&
                $this->forms_data->default_from_email
            ) {
                $sending_method = 'smtp';
                $smtp = (object) [
                    'host' => '',
                    'port' => '',
                    'user' => '',
                    'pass' => '',
                    'security' => '',
                ];
                $from_email = $this->forms_data->default_from_email;
            } else {
                return false;
            }
        }

        try {
            $mailer = new PHPMailer(true);
            $mailer->isHTML(true);
            $mailer->CharSet = 'UTF-8';
            $mailer->XMailer = ' ';
            $mailer->setFrom($from_email, $mail->from_name);
            $mailer->Subject = $subject;
            $mailer->Body = $message_html;
            $mailer->AltBody = $message_plain;

            foreach ($mail->to_email as $email) {
                $mailer->addAddress($email);
            }

            if (!empty($mail->reply_to_email)) {
                $mailer->addReplyTo(
                    $mail->reply_to_email,
                    $mail->reply_to_name ?? '',
                );
            }

            foreach ($this->mail->attachments as $attachment) {
                $mailer->addAttachment(
                    $attachment['path'],
                    $attachment['name'],
                );
            }

            if ($sending_method == 'smtp') {
                $mailer->isSMTP();
                $mailer->Host = $smtp->host ?? '';
                $mailer->Port = $smtp->port ?? '';
                $mailer->SMTPAuth = true;
                $mailer->Username = $smtp->user ?? '';
                $mailer->Password = $smtp->pass ?? '';
                $mailer->SMTPSecure = $smtp->security ?? '';
            } else {
                $from_email_domain_name = preg_replace(
                    '/.*@/',
                    '',
                    $from_email,
                );

                if (!$this->hasSpf($from_email_domain_name)) {
                    return false;
                }

                if ($this->form_data->mail->dkim ?? false) {
                    if (
                        !$this->hasDkim(
                            $from_email_domain_name,
                            $mail->dkim_selector,
                            $this->forms_data->dkim_public_key,
                        )
                    ) {
                        return false;
                    }

                    $mailer->DKIM_domain = $from_email_domain_name;
                    $mailer->DKIM_private_string =
                        $this->forms_data->dkim_private_key;
                    $mailer->DKIM_selector = $mail->dkim_selector;
                    $mailer->DKIM_identity = $from_email;
                }
            }

            return $mailer->send();
        } catch (Exception $e) {
            $this->mailer_error = $e->getMessage();

            return false;
        }
    }

    function setMail(string $language): void
    {
        if ($this->mail && $this->mail->language == $language) {
            return;
        }

        $this->mail = (object) [
            'language' => $language,
            'message' => (object) [
                'plain' => '',
                'html' => '',
            ],
            'attachments' => [],
        ];

        $message_plain = [];
        $message_html = [];

        if ($this->referer) {
            $message_plain[] = $this->referer;

            $message_html[] = <<<HTML
            <p>{$this->referer}</p>
            HTML;
        }

        foreach ($this->fields as $name => $field) {
            $value = $_POST[$name] ?? ($_FILES[$name]['name'] ?? '');

            if (!$value) {
                continue;
            }

            $value = match (gettype($value)) {
                'string' => trim($value),
                'array' => implode(', ', $value),
                default => (string) $value,
            };

            $type = $field->type ?? '';

            $label = is_string($field)
                ? $field
                : $field->label->$language ?? ($field->label->default ?? '');

            if ($type == 'hidden') {
                $label = $value;
                $value = '';
            }

            $label_plain = '';
            $label_html = '';

            if ($label !== '') {
                $label_plain = "{$label}:";
                $label_html = htmlentities($label);
                $label_html = "<b>{$label_html}</b>";

                if ($value !== '') {
                    $label_plain .= "\n";
                    $label_html .= "<br>\n";
                }
            }

            $value_html = nl2br(htmlentities($value), false);

            $message_plain[] = $label_plain . $value;

            $message_html[] = <<<HTML
            <p>{$label_html}{$value_html}</p>
            HTML;
        }

        $this->mail->message->plain = implode("\n\n", $message_plain);
        $this->mail->message->html = implode("\n", $message_html);

        foreach ($_FILES as $value) {
            $this->mail->attachments[] = [
                'path' => $value['tmp_name'],
                'name' => $this->uniqueFilename($value['name']),
            ];
        }
    }

    private function uniqueFilename(string $filename, int $number = 0): string
    {
        $unique_filename = $number
            ? preg_replace('/\.[^.]+$/i', " ({$number})$0", $filename)
            : $filename;

        if (in_array($unique_filename, $this->filenames)) {
            return $this->uniqueFilename($filename, $number + 1);
        }

        return $this->filenames[] = $unique_filename;
    }

    function getDns(string $domain_name, string $type): array
    {
        $ch = curl_init(
            "https://cloudflare-dns.com/dns-query?name={$domain_name}&type={$type}",
        );

        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/dns-json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = json_decode(curl_exec($ch), true);

        return array_map(function ($record) {
            $record['data'] = preg_replace(
                '/\s*"([^"]*)"\s*/',
                '$1',
                $record['data'],
            );

            return $record;
        }, $response['Answer'] ?? []);
    }

    function hasSpf(string $domain_name): bool
    {
        $dns_txts = $this->getDns($domain_name, 'TXT');
        $spf_include_pattern = preg_quote($this->spf_include);

        foreach ($dns_txts as $dns_txt) {
            if (preg_match('/^v=spf1\s/', $dns_txt['data'])) {
                if (
                    preg_match(
                        "/\s{$spf_include_pattern}(\s|$)/",
                        $dns_txt['data'],
                    )
                ) {
                    return true;
                }
                break;
            }
        }

        return false;
    }

    function hasDkim(
        string $domain_name,
        string $selector,
        string $public_key,
    ): bool {
        $dns_txts = $this->getDns(
            $selector . '._domainkey.' . $domain_name,
            'TXT',
        );
        $value =
            'v=DKIM1; h=sha256; t=s; p=' .
            preg_replace(['/^-+.*?-+$/m', '/[\r\n]/'], '', $public_key);

        return in_array($value, array_column($dns_txts, 'data'));
    }
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    (new Response())->handle();
}
?>
