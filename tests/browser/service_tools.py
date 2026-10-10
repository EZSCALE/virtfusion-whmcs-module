"""Isolated browser verification. All HTTP requests are intercepted; no live accounts."""
import json
from pathlib import Path
from urllib.parse import parse_qs, urlparse
from playwright.sync_api import sync_playwright

script = Path('/workspace/modules/servers/VirtFusionDirect/templates/js/tools.js').read_text()
with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, args=['--no-sandbox'])
    page = browser.new_page()
    requests = []
    session_mode = 'valid'
    page.add_init_script("""window.sessionChecks = []; window.setInterval = function (fn, ms) {
        if (ms === 30000) window.sessionChecks.push(fn); return window.sessionChecks.length;
    };""")
    features = {key: True for key in ['network','disks','backups','rescue','elevation','iso','isoLibrary','firewall','power','rdns','customUrls']}
    features['adminTools'] = False
    def route(request_route):
        request = request_route.request
        parsed = urlparse(request.url)
        if parsed.path == '/service':
            html = '<input type="hidden" name="token" value="private-csrf"><div class="vfd-tools" data-base="https://whmcs.example.test/" data-service="1" data-admin="0" data-pack="0"></div><script src="/tools.js"></script>'
            request_route.fulfill(status=200, content_type='text/html', body=html)
        elif parsed.path == '/tools.js':
            request_route.fulfill(status=200, content_type='application/javascript', body=script)
        else:
            query = parse_qs(parsed.query)
            requests.append((request.method, query, parse_qs(request.post_data or ''), request.headers))
            if query.get('tool') == ['sessionStatus']:
                if session_mode == 'network':
                    request_route.abort('failed')
                elif session_mode == 'logout':
                    request_route.fulfill(status=401, content_type='application/json', body=json.dumps({'success': False, 'errors': 'Authentication required.'}))
                else:
                    request_route.fulfill(status=200, content_type='application/json', body=json.dumps({'success': True, 'data': {'authenticated': True if session_mode == 'valid' else 'true'}}))
                return
            if query.get('action') == ['capabilities']:
                data = {'version': '7.1.0', 'features': features, 'isoLimit': 5}
            elif query.get('tool') == ['rescueStatus']:
                data = {'data': {'active': True, 'ssh': {'password': '<img src=x onerror="window.hacked=true">'}}}
            else:
                data = {'operationId': 'a'*32, 'state': 'queued', 'message': 'Request accepted'}
            request_route.fulfill(status=200, content_type='application/json', body=json.dumps({'success': True, 'data': data}))
    page.route('**/*', route)
    page.goto('https://whmcs.example.test/service')
    page.get_by_text('Rescue', exact=True).click()
    page.get_by_role('button', name='Rescue status', exact=True).click()
    page.wait_for_function("document.querySelector('pre').textContent.includes('onerror')")
    assert page.evaluate('window.hacked === undefined')
    assert page.locator('.vfd-tools img').count() == 0
    assert page.locator('textarea').count() == 0, 'Customer has no admin action editor'
    page.get_by_label('Image ID from rescue status').fill('1')
    page.on('dialog', lambda dialog: dialog.accept())
    page.get_by_role('button', name='Start rescue', exact=True).click()
    page.wait_for_function("document.querySelector('pre').textContent.includes('queued')")
    method, query, data, headers = [req for req in requests if req[1].get('tool') == ['rescueStart']][0]
    assert method == 'POST'
    assert data['token'] == ['private-csrf']
    assert 'token' not in query
    payload = json.loads(data['payload'][0])
    assert payload['imageId'] == '1' and payload['restartConfirmed'] is True
    assert len(payload['requestId']) == 32
    assert headers.get('referer') is None
    assert page.evaluate('localStorage.length + sessionStorage.length') == 0
    # A panel returning a known older version supplies no enabled 7.1 controls.
    features.update({key: False for key in features})
    page.reload()
    page.get_by_text('Additional controls are disabled for this panel.').wait_for()
    assert page.get_by_role('button', name='Start rescue', exact=True).count() == 0
    features.update({key: True for key in features})
    features['adminTools'] = False
    for rejected in ['logout', 'malformed', 'network']:
        session_mode = 'valid'
        page.reload()
        page.get_by_text('Rescue', exact=True).click()
        page.get_by_role('button', name='Rescue status', exact=True).click()
        page.wait_for_function("document.querySelector('pre').textContent.includes('onerror')")
        session_mode = rejected
        page.evaluate('window.sessionChecks.forEach(fn => fn())')
        page.get_by_text('Service session ended. Reload to authenticate again.', exact=True).wait_for()
        assert page.get_by_role('button').count() == 0
        assert 'onerror' not in page.locator('.vfd-tools').inner_text()
        assert page.evaluate('localStorage.length + sessionStorage.length') == 0
    session_mode = 'valid'
    page.reload()
    page.get_by_text('Rescue', exact=True).click()
    page.get_by_role('button', name='Rescue status', exact=True).click()
    page.wait_for_function("document.querySelector('pre').textContent.includes('onerror')")
    page.evaluate("window.dispatchEvent(new Event('pagehide'))")
    assert 'onerror' not in page.locator('.vfd-tools').inner_text()
    browser.close()
print('Browser checks passed: CSRF body, no token URLs/referrer/storage, escaped credentials, disabled features, credential teardown on logout/malformed authorization/network failure/navigation.')
