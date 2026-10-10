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
    browser.close()
print('Browser checks passed: CSRF body, no token URLs/referrer/storage, escaped credentials, no customer admin controls, disabled feature rendering.')
