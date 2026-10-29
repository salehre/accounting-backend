{
"info": {
"name": "Accounting API - Users and roles",
"description": "Before each run: php artisan cache:clear (Git Bash, inside backend). Uses the same environment as the other collections. Creates a new user with a unique name on every run.",
"schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
},
"auth": { "type": "bearer", "bearer": [{ "key": "token", "value": "{{adminToken}}", "type": "string" }] },
"event": [
{
"listen": "prerequest",
"script": { "type": "text/javascript", "exec": ["pm.request.headers.upsert({ key: 'Accept', value: 'application/json' });"] }
}
],
"item": [
{
"name": "00 Login admin",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('200', () => pm.response.to.have.status(200));",
"if (pm.response.code === 200) { const j = pm.response.json(); pm.environment.set('adminToken', j.token); pm.environment.set('adminId', j.user.id); }"
] } }],
"request": { "auth": { "type": "noauth" }, "method": "POST", "header": [],
"body": { "mode": "raw", "raw": "{\"username\":\"{{adminUsername}}\",\"password\":\"{{adminPassword}}\",\"device_name\":\"postman\"}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/auth/token" }
},
{
"name": "01 Login viewer",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('200', () => pm.response.to.have.status(200));",
"if (pm.response.code === 200) pm.environment.set('viewerToken', pm.response.json().token);"
] } }],
"request": { "auth": { "type": "noauth" }, "method": "POST", "header": [],
"body": { "mode": "raw", "raw": "{\"username\":\"{{viewerUsername}}\",\"password\":\"{{viewerPassword}}\",\"device_name\":\"postman\"}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/auth/token" }
},
{
"name": "02 No token: list users (expect 401)",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": ["pm.test('401', () => pm.response.to.have.status(401));"] } }],
"request": { "auth": { "type": "noauth" }, "method": "GET", "header": [], "url": "{{baseUrl}}/users" }
},
{
"name": "03 Viewer: list users (expect 403)",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('403', () => pm.response.to.have.status(403));",
"pm.test('message explains why', () => pm.expect(pm.response.json().message).to.include('permission'));"
] } }],
"request": { "auth": { "type": "bearer", "bearer": [{ "key": "token", "value": "{{viewerToken}}", "type": "string" }] },
"method": "GET", "header": [], "url": "{{baseUrl}}/users" }
},
{
"name": "04 Roles",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('200', () => pm.response.to.have.status(200));",
"const roles = pm.response.json();",
"const idOf = (name) => (roles.find(r => r.name === name) || {}).id;",
"pm.test('admin, accountant and viewer exist', () => pm.expect([idOf('admin'), idOf('accountant'), idOf('viewer')]).to.not.include(undefined));",
"pm.test('admin has the wildcard permission', () => pm.expect(roles.find(r => r.name === 'admin').permissions).to.include('*'));",
"pm.environment.set('roleAdmin', idOf('admin'));",
"pm.environment.set('roleAccountant', idOf('accountant'));",
"pm.environment.set('roleViewer', idOf('viewer'));"
] } }],
"request": { "method": "GET", "header": [], "url": "{{baseUrl}}/roles" }
},
{
"name": "05 Create user",
"event": [
{ "listen": "prerequest", "script": { "type": "text/javascript", "exec": [
"pm.environment.set('newUsername', 'pm' + Date.now());",
"pm.environment.set('newUserPassword', 'first-strong-pass-1');"
] } },
{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('201 Created', () => pm.response.to.have.status(201));",
"const j = pm.response.json();",
"pm.test('role is viewer and the user is active', () => { pm.expect(j.role).to.eql('viewer'); pm.expect(j.isActive).to.eql(true); });",
"pm.test('only safe fields are returned', () => pm.expect(Object.keys(j).sort()).to.eql(['createdAt','fullName','id','isActive','lastLoginAt','role','roleId','username']));",
"pm.environment.set('newUserId', j.id);"
] } }
],
"request": { "method": "POST", "header": [],
"body": { "mode": "raw", "raw": "{\"username\":\"{{newUsername}}\",\"fullName\":\"Postman User\",\"password\":\"{{newUserPassword}}\",\"roleId\":{{roleViewer}}}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/users" }
},
{
"name": "06 Create the same username in upper case (expect 422)",
"event": [
{ "listen": "prerequest", "script": { "type": "text/javascript", "exec": ["pm.environment.set('dupUsername', pm.environment.get('newUsername').toUpperCase());"] } },
{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('422', () => pm.response.to.have.status(422));",
"pm.test('error points at username', () => pm.expect(pm.response.json().errors).to.have.property('username'));"
] } }
],
"request": { "method": "POST", "header": [],
"body": { "mode": "raw", "raw": "{\"username\":\"{{dupUsername}}\",\"fullName\":\"Duplicate\",\"password\":\"first-strong-pass-1\",\"roleId\":{{roleViewer}}}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/users" }
},
{
"name": "07 Create with a short password (expect 422)",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('422', () => pm.response.to.have.status(422));",
"pm.test('error points at password', () => pm.expect(pm.response.json().errors).to.have.property('password'));"
] } }],
"request": { "method": "POST", "header": [],
"body": { "mode": "raw", "raw": "{\"username\":\"pmweakuser\",\"fullName\":\"Weak\",\"password\":\"short\",\"roleId\":{{roleViewer}}}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/users" }
},
{
"name": "08 Create with a too short username (expect 422)",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('422', () => pm.response.to.have.status(422));",
"pm.test('error points at username', () => pm.expect(pm.response.json().errors).to.have.property('username'));"
] } }],
"request": { "method": "POST", "header": [],
"body": { "mode": "raw", "raw": "{\"username\":\"ab\",\"fullName\":\"Short\",\"password\":\"first-strong-pass-1\",\"roleId\":{{roleViewer}}}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/users" }
},
{
"name": "09 Create with an unknown role (expect 422)",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('422', () => pm.response.to.have.status(422));",
"pm.test('error points at roleId', () => pm.expect(pm.response.json().errors).to.have.property('roleId'));"
] } }],
"request": { "method": "POST", "header": [],
"body": { "mode": "raw", "raw": "{\"username\":\"pmnorole\",\"fullName\":\"No role\",\"password\":\"first-strong-pass-1\",\"roleId\":999999}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/users" }
},
{
"name": "10 Login as the new user",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('200', () => pm.response.to.have.status(200));",
"if (pm.response.code === 200) pm.environment.set('newUserToken', pm.response.json().token);"
] } }],
"request": { "auth": { "type": "noauth" }, "method": "POST", "header": [],
"body": { "mode": "raw", "raw": "{\"username\":\"{{newUsername}}\",\"password\":\"{{newUserPassword}}\",\"device_name\":\"postman\"}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/auth/token" }
},
{
"name": "11 New user: me",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('200', () => pm.response.to.have.status(200));",
"const j = pm.response.json();",
"pm.test('viewer without full access', () => { pm.expect(j.role).to.eql('viewer'); pm.expect(j.permissions).to.not.include('*'); });"
] } }],
"request": { "auth": { "type": "bearer", "bearer": [{ "key": "token", "value": "{{newUserToken}}", "type": "string" }] },
"method": "GET", "header": [], "url": "{{baseUrl}}/auth/me" }
},
{
"name": "12 New user: list users (expect 403)",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": ["pm.test('403', () => pm.response.to.have.status(403));"] } }],
"request": { "auth": { "type": "bearer", "bearer": [{ "key": "token", "value": "{{newUserToken}}", "type": "string" }] },
"method": "GET", "header": [], "url": "{{baseUrl}}/users" }
},
{
"name": "13 Search users",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('200', () => pm.response.to.have.status(200));",
"const j = pm.response.json();",
"pm.test('exactly our user is found', () => { pm.expect(j.total).to.eql(1); pm.expect(j.items[0].id).to.eql(Number(pm.environment.get('newUserId'))); });",
"pm.test('no password data in the answer', () => pm.expect(pm.response.text().toLowerCase()).to.not.include('password'));"
] } }],
"request": { "method": "GET", "header": [], "url": "{{baseUrl}}/users?search={{newUsername}}" }
},
{
"name": "14 Show user",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('200', () => pm.response.to.have.status(200));",
"pm.test('username matches', () => pm.expect(pm.response.json().username).to.eql(pm.environment.get('newUsername')));"
] } }],
"request": { "method": "GET", "header": [], "url": "{{baseUrl}}/users/{{newUserId}}" }
},
{
"name": "15 Update name and role",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('200', () => pm.response.to.have.status(200));",
"const j = pm.response.json();",
"pm.test('new name and role', () => { pm.expect(j.fullName).to.eql('Postman Edited'); pm.expect(j.role).to.eql('accountant'); });"
] } }],
"request": { "method": "PATCH", "header": [],
"body": { "mode": "raw", "raw": "{\"fullName\":\"Postman Edited\",\"roleId\":{{roleAccountant}}}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/users/{{newUserId}}" }
},
{
"name": "16 New user: the OLD token already has the new permissions",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('200', () => pm.response.to.have.status(200));",
"pm.test('accountant permissions without a new login', () => pm.expect(pm.response.json().permissions).to.include('entries.post'));"
] } }],
"request": { "auth": { "type": "bearer", "bearer": [{ "key": "token", "value": "{{newUserToken}}", "type": "string" }] },
"method": "GET", "header": [], "url": "{{baseUrl}}/auth/me" }
},
{
"name": "17 Update with an empty body (expect 422)",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": ["pm.test('422', () => pm.response.to.have.status(422));"] } }],
"request": { "method": "PATCH", "header": [],
"body": { "mode": "raw", "raw": "{}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/users/{{newUserId}}" }
},
{
"name": "18 Update with an unknown role (expect 422)",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('422', () => pm.response.to.have.status(422));",
"pm.test('error points at roleId', () => pm.expect(pm.response.json().errors).to.have.property('roleId'));"
] } }],
"request": { "method": "PATCH", "header": [],
"body": { "mode": "raw", "raw": "{\"roleId\":999999}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/users/{{newUserId}}" }
},
{
"name": "19 Admin disables himself (expect 422)",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('422', () => pm.response.to.have.status(422));",
"pm.test('explains why', () => pm.expect(pm.response.json().message).to.include('your own'));"
] } }],
"request": { "method": "PATCH", "header": [],
"body": { "mode": "raw", "raw": "{\"isActive\":false}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/users/{{adminId}}" }
},
{
"name": "20 Disable the new user",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('200', () => pm.response.to.have.status(200));",
"pm.test('isActive is false', () => pm.expect(pm.response.json().isActive).to.eql(false));"
] } }],
"request": { "method": "PATCH", "header": [],
"body": { "mode": "raw", "raw": "{\"isActive\":false}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/users/{{newUserId}}" }
},
{
"name": "21 New user: old token is revoked (expect 401)",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": ["pm.test('401', () => pm.response.to.have.status(401));"] } }],
"request": { "auth": { "type": "bearer", "bearer": [{ "key": "token", "value": "{{newUserToken}}", "type": "string" }] },
"method": "GET", "header": [], "url": "{{baseUrl}}/auth/me" }
},
{
"name": "22 New user: cannot log in while disabled (expect 401)",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": ["pm.test('401', () => pm.response.to.have.status(401));"] } }],
"request": { "auth": { "type": "noauth" }, "method": "POST", "header": [],
"body": { "mode": "raw", "raw": "{\"username\":\"{{newUsername}}\",\"password\":\"{{newUserPassword}}\",\"device_name\":\"postman\"}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/auth/token" }
},
{
"name": "23 Enable the new user again",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('200', () => pm.response.to.have.status(200));",
"pm.test('isActive is true', () => pm.expect(pm.response.json().isActive).to.eql(true));"
] } }],
"request": { "method": "PATCH", "header": [],
"body": { "mode": "raw", "raw": "{\"isActive\":true}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/users/{{newUserId}}" }
},
{
"name": "24 Reset the new user's password",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": [
"pm.test('200', () => pm.response.to.have.status(200));",
"pm.test('ok', () => pm.expect(pm.response.json().ok).to.eql(true));"
] } }],
"request": { "method": "POST", "header": [],
"body": { "mode": "raw", "raw": "{\"newPassword\":\"second-strong-pass-2\"}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/users/{{newUserId}}/reset-password" }
},
{
"name": "25 Login with the OLD password (expect 401)",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": ["pm.test('401', () => pm.response.to.have.status(401));"] } }],
"request": { "auth": { "type": "noauth" }, "method": "POST", "header": [],
"body": { "mode": "raw", "raw": "{\"username\":\"{{newUsername}}\",\"password\":\"{{newUserPassword}}\",\"device_name\":\"postman\"}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/auth/token" }
},
{
"name": "26 Login with the NEW password",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": ["pm.test('200', () => pm.response.to.have.status(200));"] } }],
"request": { "auth": { "type": "noauth" }, "method": "POST", "header": [],
"body": { "mode": "raw", "raw": "{\"username\":\"{{newUsername}}\",\"password\":\"second-strong-pass-2\",\"device_name\":\"postman\"}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/auth/token" }
},
{
"name": "27 Admin resets his own password this way (expect 422)",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": ["pm.test('422', () => pm.response.to.have.status(422));"] } }],
"request": { "method": "POST", "header": [],
"body": { "mode": "raw", "raw": "{\"newPassword\":\"second-strong-pass-2\"}", "options": { "raw": { "language": "json" } } },
"url": "{{baseUrl}}/users/{{adminId}}/reset-password" }
},
{
"name": "28 Unknown user (expect 404)",
"event": [{ "listen": "test", "script": { "type": "text/javascript", "exec": ["pm.test('404', () => pm.response.to.have.status(404));"] } }],
"request": { "method": "GET", "header": [], "url": "{{baseUrl}}/users/999999" }
}
]
}
