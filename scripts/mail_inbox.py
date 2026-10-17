#!/usr/bin/env python3
"""Ищет в ящике (IMAP) письмо по уникальной метке в теме — для живых тестов почты.

    mail_inbox.py --token LIVE-ab12cd [--wait 60] [--save-dir DIR] [--expect 1]

Логин и пароль берутся из окружения (не из аргументов, чтобы не светились в списке процессов):
MAIL_IMAP_USER, MAIL_IMAP_PASS, MAIL_IMAP_HOST (по умолчанию imap.gmail.com).

Печатает одну JSON-строку:
  {"ok": true, "messages": [{"subject", "to", "html", "text", "links": [...],
                             "attachments": [{"filename", "content_type", "size", "path"}]}]}
Ждёт до --wait секунд, пока не появится хотя бы --expect писем с такой меткой
(--expect 0 — «убедиться, что письма нет»: ждёт всё время и ничего не находит).
"""
import argparse
import email
import imaplib
import json
import os
import re
import sys
import time
from email.header import decode_header, make_header


def decode(value):
    return str(make_header(decode_header(value))) if value else ''


def parse(raw, save_dir):
    msg = email.message_from_bytes(raw)
    html, text, attachments = '', '', []

    for part in msg.walk():
        if part.is_multipart():
            continue

        filename = decode(part.get_filename())
        payload = part.get_payload(decode=True) or b''
        ctype = part.get_content_type()

        if filename:
            path = None
            if save_dir:
                os.makedirs(save_dir, exist_ok=True)
                path = os.path.join(save_dir, filename)
                with open(path, 'wb') as handle:
                    handle.write(payload)
            attachments.append({'filename': filename, 'content_type': ctype, 'size': len(payload), 'path': path})
        elif ctype == 'text/html':
            html += payload.decode(part.get_content_charset() or 'utf-8', 'replace')
        elif ctype == 'text/plain':
            text += payload.decode(part.get_content_charset() or 'utf-8', 'replace')

    return {
        'subject': decode(msg['Subject']),
        'to': decode(msg['To']),
        'html': html,
        'text': text,
        'links': re.findall(r'href="([^"]+)"', html),
        'attachments': attachments,
    }


def search(client, token):
    found = []
    # Gmail кладёт письмо самому себе во «Входящие», но на всякий случай смотрим и «Спам».
    for folder in ('INBOX', '[Gmail]/Spam'):
        try:
            if client.select(f'"{folder}"', readonly=True)[0] != 'OK':
                continue
        except imaplib.IMAP4.error:
            continue

        status, data = client.search(None, 'SUBJECT', f'"{token}"')
        if status != 'OK':
            continue

        for number in data[0].split():
            status, chunks = client.fetch(number, '(RFC822)')
            if status == 'OK' and chunks and chunks[0]:
                found.append((folder, chunks[0][1]))

    return found


def main():
    args = argparse.ArgumentParser()
    args.add_argument('--token', required=True)
    args.add_argument('--wait', type=int, default=60)
    args.add_argument('--expect', type=int, default=1)
    args.add_argument('--save-dir')
    options = args.parse_args()

    user, password = os.environ.get('MAIL_IMAP_USER'), os.environ.get('MAIL_IMAP_PASS')
    host = os.environ.get('MAIL_IMAP_HOST', 'imap.gmail.com')

    if not user or not password:
        print(json.dumps({'ok': False, 'error': 'Не заданы MAIL_IMAP_USER / MAIL_IMAP_PASS'}))
        return 1

    deadline = time.time() + options.wait
    found = []

    try:
        client = imaplib.IMAP4_SSL(host, 993, timeout=30)
        client.login(user, password.replace(' ', ''))

        while True:
            found = search(client, options.token)

            if options.expect and len(found) >= options.expect:
                break
            if time.time() >= deadline:
                break

            time.sleep(3)

        client.logout()
    except Exception as error:  # noqa: BLE001 — любая сетевая/IMAP-ошибка должна дойти до теста текстом
        print(json.dumps({'ok': False, 'error': f'{type(error).__name__}: {error}'}))
        return 1

    messages = []
    for index, (folder, raw) in enumerate(found):
        directory = os.path.join(options.save_dir, str(index)) if options.save_dir else None
        item = parse(raw, directory)
        item['folder'] = folder
        messages.append(item)

    print(json.dumps({'ok': True, 'messages': messages}, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    sys.exit(main())
