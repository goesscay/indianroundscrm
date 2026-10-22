<?php

namespace App\Support;

/** The signature at the end of the supplier mails. Change the CRM_SIGNATURE_* values in .env to put someone else's name on it. */
class MailSignature
{
    /** @return array{name:string,phone:string,title:string,company:string} */
    public static function details(): array
    {
        $c = (array) config('crm.signature');

        return [
            'name' => (string) ($c['name'] ?? ''), 'phone' => (string) ($c['phone'] ?? ''),
            'title' => (string) ($c['title'] ?? ''), 'company' => (string) ($c['company'] ?? config('crm.company.name', 'Indian Rounds')),
        ];
    }

    /** The plain-text lines: Regards, name, phone, title, company. @return list<string> */
    public static function lines(): array
    {
        $d = self::details();

        return array_values(array_filter(['Regards,', $d['name'], $d['phone'], $d['title'], $d['company']], fn ($l) => $l !== ''));
    }
}
