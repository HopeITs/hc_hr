import json
B='http://127.0.0.1:8406'
PW=json.load(open('C:/laragon/tmp/hc-hr-gr/x2-session-manifest.json'))['password']
def login(ctx, email, locale='en'):
    pg=ctx.new_page()
    pg.goto(B+f'/{locale}/login')
    pg.fill('input[type=email]', email)
    pg.fill('input[type=password]', PW)
    pg.click('button[type=submit]')
    pg.wait_for_load_state('networkidle')
    return pg
