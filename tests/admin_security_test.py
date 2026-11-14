"""Run isolated admin HTTP security checks with a supplied PHP binary.

python3 tests/admin_security_test.py --php /path/to/php
The suite uses a temporary document root and synthetic credentials only.
"""
import argparse
import http.cookiejar
import json
import pathlib
import re
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, new_url):
        return None


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())

    def request(self, path='', data=None, cookie=None):
        headers = {'Cookie': cookie} if cookie else {}
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        request = urllib.request.Request(self.base + '/kugou_admin.php' + path, data=body, headers=headers)
        try:
            response = self.opener.open(request, timeout=5)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(), response.headers

    def csrf(self):
        status, body, _ = self.request()
        if status != 200:
            raise AssertionError('Admin page returned ' + str(status))
        return re.search(r'name="csrf_token" value="([a-f0-9]{64})"', body).group(1)

    def session_id(self):
        return next(cookie.value for cookie in self.jar if cookie.name == 'MKMusicAdmin')


class AdminSecurityTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='mkmusic-admin-test-')
        cls.root = pathlib.Path(cls.temp.name)
        shutil.copy2(ARGS.source / 'kugou_admin.php', cls.root / 'kugou_admin.php')
        (cls.root / 'cache').mkdir()
        (cls.root / 'sessions').mkdir()
        cls.password = secrets.token_urlsafe(24)
        hashed = subprocess.check_output([ARGS.php, '-r',
            'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);'],
            input=cls.password.encode()).decode()
        (cls.root / 'config.local.php').write_text(
            "<?php return ['admin_password_hash' => '" + hashed + "'];", encoding='utf-8')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        cls.base = 'http://127.0.0.1:' + str(port)
        cls.log = (cls.root / 'server.log').open('wb')
        cls.server = subprocess.Popen([ARGS.php, '-d', 'session.save_path=' + str(cls.root / 'sessions'),
            '-d', 'display_errors=0', '-S', '127.0.0.1:' + str(port), '-t', str(cls.root)],
            stdout=cls.log, stderr=cls.log, cwd=cls.root)
        for _ in range(50):
            try:
                if Client(cls.base).request()[0] == 200:
                    break
            except OSError:
                pass
            time.sleep(0.1)
        else:
            raise RuntimeError('Test PHP server did not start')

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate()
        cls.server.wait(timeout=5)
        cls.log.close()
        cls.temp.cleanup()

    def test_admin_session_csrf_and_rate_limit(self):
        client = Client(self.base)
        self.assertEqual(client.request('?action=status')[0], 401)
        token = client.csrf()
        old_session = client.session_id()
        self.assertEqual(client.request(data={'password': self.password})[0], 403)
        self.assertEqual(client.request(data={'password': self.password, 'csrf_token': 'bad'})[0], 403)
        status, _, headers = client.request(data={'password': self.password, 'csrf_token': token})
        self.assertEqual(status, 303)
        self.assertNotEqual(client.session_id(), old_session)
        self.assertIn('HttpOnly', headers['Set-Cookie'])
        self.assertIn('SameSite=Strict', headers['Set-Cookie'])
        token = client.csrf()
        self.assertEqual(Client(self.base).request('?action=status', cookie='MKMusicAdmin=' + old_session)[0], 401)

        cookie_file = self.root / 'cache/kugou_cookie.txt'
        cookie_file.write_text('t=synthetic-secret; KugooID=123456')
        status, body, _ = client.request('?action=status')
        self.assertEqual(status, 200)
        self.assertEqual(json.loads(body)['userid'], '123456')
        self.assertNotIn('synthetic-secret', body)
        self.assertNotIn('token_preview', body)
        self.assertEqual(client.request('?action=clear')[0], 405)
        self.assertTrue(cookie_file.exists())
        self.assertEqual(client.request(data={'action': 'clear', 'csrf_token': 'bad'})[0], 403)
        self.assertTrue(cookie_file.exists())
        self.assertEqual(client.request(data={'action': 'clear', 'csrf_token': token})[0], 200)
        self.assertFalse(cookie_file.exists())
        self.assertEqual(client.request(data={'action': 'check', 'qrcode': 'fabricated', 'csrf_token': token})[0], 400)
        self.assertEqual(client.request('?logout=1')[0], 405)
        self.assertEqual(client.request('?action=status')[0], 200)
        self.assertEqual(client.request(data={'action': 'logout', 'csrf_token': 'bad'})[0], 403)
        self.assertEqual(client.request(data={'action': 'logout', 'csrf_token': token})[0], 303)
        self.assertEqual(client.request('?action=status')[0], 401)

        # Fresh cookies do not bypass the server-side IP counter.
        for attempt in range(6):
            attacker = Client(self.base)
            status, _, headers = attacker.request(data={'password': 'incorrect', 'csrf_token': attacker.csrf()})
            self.assertEqual(status, 401 if attempt < 5 else 429)
            if attempt == 5:
                self.assertGreater(int(headers['Retry-After']), 0)
        self.assertEqual(len(list((self.root / 'cache/admin-login-ratelimit').glob('*.json'))), 1)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--php', required=True)
    parser.add_argument('--source', type=pathlib.Path, default=pathlib.Path(__file__).resolve().parents[1])
    ARGS, extra = parser.parse_known_args()
    unittest.main(argv=[__file__] + extra)
