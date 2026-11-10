# حسابات تجربة محلية / Local test accounts

**الرابط / Link:** http://localhost:8001/login (English: http://localhost:8001/en/login)

**كلمة السر لكل الحسابات / Password for every account:** `Hope-ad29b8d7d0d3!`

| الحساب / Account | الرول / Role | مربوط بالموظف / Linked employee |
|---|---|---|
| super-admin@hopehr.test | super-admin | فريدي يوسف |
| general-manager@hopehr.test | general-manager | فريدي يوسف |
| hr-syr@hopehr.test | HR-SYR (كل المدن / all cities) | لانا عبدو |
| hr-alp@hopehr.test | HR-ALP (حلب) | فريدي يوسف |
| hr-dms@hopehr.test | HR-DMS (دمشق) | ميرنا بدره |
| hr-hms@hopehr.test | HR-HMS (حمص) | رين اسعد |
| hr-lat@hopehr.test | HR-LAT (اللاذقية) | لينا صايغ |
| hr-tar@hopehr.test | HR-TAR (طرطوس) | رين بشاره |
| manager@hopehr.test | manager | جودي داداأوغليان |
| employee@hopehr.test | employee | مجد زريق |
| multi-role@hopehr.test | manager + HR-ALP + employee (لتجربة تبديل الرول / to try switching) | جودي داداأوغليان |

- Local only: the seeder refuses any other environment. Recreate or update with
  `php artisan db:seed --class=LocalTestAccountsSeeder` (password: `LOCAL_TEST_ACCOUNTS_PASSWORD` in `.env`).
- Sign-in needs an active employee, so each account is linked to a real active employee of this
  local copy and also sees that person's own pages ("My leaves", their profile).
- Five wrong passwords in a minute lock that account for a minute (the sign-in rate limit).
- Don't copy these accounts to production.
