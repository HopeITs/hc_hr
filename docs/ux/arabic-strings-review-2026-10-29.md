# Arabic strings to review (2026-10-29)

Every Arabic string added today in `resources/lang/ar/{dashboard,dashboard_clock,mobile,examples}.php`:
keys present in the working tree but not at commit `b81e778b` (uncommitted changes included).
`dashboard_clock.php` and `examples.php` are new files, so all their keys are listed.
No existing Arabic value changed in these files since `b81e778b`; nothing here has been edited.

Counts: dashboard 104, dashboard_clock 11, mobile 10, examples 42 (167 in all).

| File | Key | English | Arabic |
|---|---|---|---|
| dashboard | `out-today.calendar` | Team calendar | تقويم الفريق |
| dashboard | `out-today.empty` | Everyone is in today | الجميع على رأس عملهم اليوم |
| dashboard | `out-today.more` | {1} and 1 more person\|[2,*] and :count more people | {1} وشخص آخر\|{2} وشخصان آخران\|[3,10] و:count أشخاص آخرين\|[11,*] و:count شخصًا آخر |
| dashboard | `next-holiday.today` | Today | اليوم |
| dashboard | `next-holiday.days-left` | {1} day to go\|[2,*] days to go | {1} يوم متبقٍّ\|{2} يومان متبقيان\|[3,10] أيام متبقية\|[11,*] يومًا متبقيًا |
| dashboard | `next-holiday.length` | {1} 1 day\|[2,*] :count days | {1} يوم واحد\|{2} يومان\|[3,10] :count أيام\|[11,*] :count يومًا |
| dashboard | `next-holiday.none` | No official holiday in the next :count days | لا عطلة رسمية خلال الأيام الـ:count القادمة |
| dashboard | `leave-balance.request` | Request leave | طلب إجازة |
| dashboard | `leave-balance.none` | No annual leave balance on your current contract | لا يوجد رصيد إجازة سنوية في عقدك الحالي |
| dashboard | `leave-balance.days-left` | {0} No days left\|{1} 1 day left\|[2,*] :count days left | {0} لم يبقَ أي يوم\|{1} بقي يوم واحد\|{2} بقي يومان\|[3,10] بقيت :count أيام\|[11,*] بقي :count يومًا |
| dashboard | `leave-balance.left-of` | {0} No annual leave days left of :total\|{1} 1 annual leave day left of :total\|[2,*] :count annual leave days left of :total | {0} لم يبقَ أي يوم من الإجازة السنوية من أصل :total\|{1} بقي يوم واحد من الإجازة السنوية من أصل :total\|{2} بقي يومان من الإجازة السنوية من أصل :total\|[3,10] بقيت :count أيام من الإجازة السنوية من أصل :total\|[11,*] بقي :count يومًا من الإجازة السنوية من أصل :total |
| dashboard | `birthdays.empty` | No birthdays in your team this week | لا أعياد ميلاد في فريقك هذا الأسبوع |
| dashboard | `birthdays.today` | Today | اليوم |
| dashboard | `tiles.late` | Past the deadline | تجاوز الموعد |
| dashboard | `tiles.decide` | Work inbox | صندوق المهام |
| dashboard | `tiles.my-leaves` | My leaves | إجازاتي |
| dashboard | `tiles.notes` | Notes | الملاحظات |
| dashboard | `tiles.team` | Team | الفريق |
| dashboard | `tiles.news` | News | الأخبار |
| dashboard | `tiles.calendar` | Calendar | التقويم |
| dashboard | `tiles.upcoming-leaves` | Upcoming leaves | الإجازات القادمة |
| dashboard | `tiles.today-events` | Today's events | أحداث اليوم |
| dashboard | `tiles.upcoming-events` | Upcoming events | الأحداث القادمة |
| dashboard | `tiles.pending-requests` | Pending requests | الطلبات المعلقة |
| dashboard | `tiles.checklist` | My items | عهدتي |
| dashboard | `tiles.badges` | My badges | شاراتي |
| dashboard | `tiles.soon-ending-contracts` | Contracts ending soon | عقود تنتهي قريبًا |
| dashboard | `tiles.stats-department` | Employees by department | الموظفون حسب القسم |
| dashboard | `tiles.stats-job-level` | Employees by job level | الموظفون حسب المستوى الوظيفي |
| dashboard | `tiles.stats-contract-type` | Employees by contract type | الموظفون حسب نوع العقد |
| dashboard | `tiles.stats-no-leaves` | Employees with no leave taken | موظفون لم يأخذوا إجازة |
| dashboard | `tiles.stats-salary` | Employees by pay basis | الموظفون حسب أساس الأجر |
| dashboard | `tiles.who-is-out` | Out today | الغائبون اليوم |
| dashboard | `tiles.next-holiday` | Next holiday | العطلة القادمة |
| dashboard | `tiles.leave-balance` | Leave balance | رصيد الإجازة |
| dashboard | `tiles.birthdays` | Birthdays this week | أعياد الميلاد هذا الأسبوع |
| dashboard | `tiles.clock` | Clock | الساعة |
| dashboard | `clock.aria` | Time in Damascus: :time, :weekday :date | الوقت في دمشق: :time، :weekday :date |
| dashboard | `clock.starts` | Your day starts in :time | يبدأ دوامك بعد :time |
| dashboard | `clock.resumes` | Your day resumes in :time | يُستأنف دوامك بعد :time |
| dashboard | `clock.break` | Your break starts in :time | تبدأ استراحتك بعد :time |
| dashboard | `clock.ends` | Your day ends in :time | ينتهي دوامك بعد :time |
| dashboard | `clock.outside` | Outside working hours | خارج ساعات الدوام |
| dashboard | `clock.off` | Day off today | اليوم يوم عطلة |
| dashboard | `home.lead` | Your own home screen. Press and hold a tile, or choose Customise, to move, resize, pin or hide tiles. | شاشتك الخاصة. اضغط مطولاً على أي مربع، أو اختر «تخصيص»، لتحريكه أو تغيير حجمه أو تثبيته أو إخفائه. |
| dashboard | `home.edit-lead` | Drag a tile to move it, or select it and use the arrow keys. Drag the dark corner to resize it cell by cell. × hides a tile, the pin keeps it first. | اسحب المربع لتحريكه، أو اختره واستخدم الأسهم. اسحب الزاوية الداكنة لتغيير حجمه خلية خلية. زر × يخفيه، والدبوس يثبّته في البداية. |
| dashboard | `home.customise` | Customise | تخصيص |
| dashboard | `home.done` | Done | تم |
| dashboard | `home.reset` | Reset | استعادة الأصل |
| dashboard | `home.reset-title` | Go back to the default dashboard? | هل تريد العودة إلى لوحة التحكم الأصلية؟ |
| dashboard | `home.reset-message` | Every tile returns to its usual place and size, and hidden tiles come back. Your notes stay. | يعود كل مربع إلى مكانه وحجمه المعتادين، وتعود المربعات المخفية. ملاحظاتك تبقى كما هي. |
| dashboard | `home.add` | Add | إضافة |
| dashboard | `home.add-title` | Add a tile | إضافة مربع |
| dashboard | `home.cards` | Cards | البطاقات |
| dashboard | `home.shortcuts` | Shortcuts | الاختصارات |
| dashboard | `home.close` | Close | إغلاق |
| dashboard | `home.hide` | Hide :tile | إخفاء :tile |
| dashboard | `home.pin` | Pin :tile first | تثبيت :tile في البداية |
| dashboard | `home.look` | Change the look of :tile | تغيير شكل :tile |
| dashboard | `home.looks.digital` | Digital | رقمية |
| dashboard | `home.looks.analog` | Analogue | بعقارب |
| dashboard | `home.resize` | Resize :tile, now :size | تغيير حجم :tile، الحجم الآن :size |
| dashboard | `home.resize-hint` | Drag to resize, cell by cell. Arrow keys work too. | اسحب لتغيير الحجم خلية خلية. تعمل الأسهم أيضاً. |
| dashboard | `home.size` | :cols by :rows cells | :cols × :rows خلايا |
| dashboard | `home.tile` | :tile, :size, position :n of :total | :tile، :size، الموضع :n من :total |
| dashboard | `home.tile-hint` | Arrow keys move this tile earlier or later. Home and End move it to the start or the end. | تنقل الأسهم هذا المربع إلى الأمام أو إلى الخلف، وينقله Home وEnd إلى البداية أو النهاية. |
| dashboard | `home.moved` | :tile moved to position :n of :total. | نُقل :tile إلى الموضع :n من :total. |
| dashboard | `home.resized` | :tile is now :size. | أصبح حجم :tile :size. |
| dashboard | `home.hidden` | :tile hidden. Add brings it back. | أُخفي :tile. يعيده زر «إضافة». |
| dashboard | `home.added` | :tile added. | أُضيف :tile. |
| dashboard | `home.pinned` | :tile pinned first. | ثُبّت :tile في البداية. |
| dashboard | `home.unpinned` | :tile no longer pinned. | أُلغي تثبيت :tile. |
| dashboard | `home.nothing` | Nothing here right now | لا شيء هنا الآن |
| dashboard | `home.page-open` | Double-click to open the page | انقر مرتين لفتح الصفحة |
| dashboard | `home.page-hint` | Double-click to open its page. | انقر مرتين لفتح صفحته. |
| dashboard | `home.page-open-link` | Open the page | افتح الصفحة |
| dashboard | `home.panel-close` | Close :tile | إغلاق :tile |
| dashboard | `late-tile.none` | Nothing is past its deadline. | لا شيء تجاوز موعده. |
| dashboard | `decide-tile.none` | Your inbox is clear. | صندوق مهامك فارغ. |
| dashboard | `decide-tile.open` | Open inbox | افتح الصندوق |
| dashboard | `decide-tile.items` | {1} 1 item to hand over or take back\|[2,*] :count items to hand over or take back | لا عناصر للتسليم أو الاسترداد\|عنصر واحد للتسليم أو الاسترداد\|عنصران للتسليم أو الاسترداد\|:count عناصر للتسليم أو الاسترداد\|:count عنصرًا للتسليم أو الاسترداد\|:count عنصر للتسليم أو الاسترداد |
| dashboard | `app-card.in-inbox` | {0} in your inbox\|{1} item in your inbox\|[2,*] items in your inbox | في صندوق مهامك\|عنصر في صندوق مهامك\|عنصران في صندوق مهامك\|عناصر في صندوق مهامك\|عنصرًا في صندوق مهامك\|عنصر في صندوق مهامك |
| dashboard | `app-card.inbox-empty` | Your inbox is clear. | صندوق مهامك فارغ. |
| dashboard | `app-card.ending-soon` | {0} contracts ending within 30 days\|{1} contract ending within 30 days\|[2,*] contracts ending within 30 days | عقود تنتهي خلال 30 يومًا\|عقد ينتهي خلال 30 يومًا\|عقدان ينتهيان خلال 30 يومًا\|عقود تنتهي خلال 30 يومًا\|عقدًا تنتهي خلال 30 يومًا\|عقد تنتهي خلال 30 يومًا |
| dashboard | `app-card.not-renewed` | Ended without renewal | انتهت دون تجديد |
| dashboard | `header.headline.leave` | {1} 1 leave request needs your decision\|[2,*] :count leave requests need your decision | لا طلبات إجازة بانتظار قرارك\|طلب إجازة واحد بانتظار قرارك\|طلبا إجازة بانتظار قرارك\|:count طلبات إجازة بانتظار قرارك\|:count طلبَ إجازة بانتظار قرارك\|:count طلب إجازة بانتظار قرارك |
| dashboard | `header.headline.decisions` | {1} 1 request needs your decision\|[2,*] :count requests need your decision | لا طلبات بانتظار قرارك\|طلب واحد بانتظار قرارك\|طلبان بانتظار قرارك\|:count طلبات بانتظار قرارك\|:count طلبًا بانتظار قرارك\|:count طلب بانتظار قرارك |
| dashboard | `header.headline.inbox` | {1} 1 item in your inbox\|[2,*] :count items in your inbox | لا عناصر في صندوق مهامك\|عنصر واحد في صندوق مهامك\|عنصران في صندوق مهامك\|:count عناصر في صندوق مهامك\|:count عنصرًا في صندوق مهامك\|:count عنصر في صندوق مهامك |
| dashboard | `header.headline.late` | {1} 1 thing is late\|[2,*] :count things are late | لا شيء متأخر\|أمر واحد متأخر\|أمران متأخران\|:count أمور متأخرة\|:count أمرًا متأخرًا\|:count أمر متأخر |
| dashboard | `header.headline.clear` | Nothing is waiting on you today | لا شيء بانتظارك اليوم |
| dashboard | `header.late.leave` | {1} 1 leave started with no decision\|[2,*] :count leaves started with no decision | لا إجازات بدأت دون قرار\|إجازة واحدة بدأت دون قرار\|إجازتان بدأتا دون قرار\|:count إجازات بدأت دون قرار\|:count إجازةً بدأت دون قرار\|:count إجازة بدأت دون قرار |
| dashboard | `header.late.contract` | {1} 1 contract ended without renewal\|[2,*] :count contracts ended without renewal | لا عقود انتهت دون تجديد\|عقد واحد انتهى دون تجديد\|عقدان انتهيا دون تجديد\|:count عقود انتهت دون تجديد\|:count عقدًا انتهت دون تجديد\|:count عقد انتهت دون تجديد |
| dashboard | `header.late.open` | Open the oldest | افتح الأقدم |
| dashboard | `header.decide.title` | {1} 1 decision waits on you\|[2,*] :count decisions wait on you | لا قرارات بانتظارك\|قرار واحد بانتظارك\|قراران بانتظارك\|:count قرارات بانتظارك\|:count قرارًا بانتظارك\|:count قرار بانتظارك |
| dashboard | `header.decide.prev` | Previous decision | القرار السابق |
| dashboard | `header.decide.next` | Next decision | القرار التالي |
| dashboard | `header.decide.all` | Show all | عرض الكل |
| dashboard | `header.decide.one` | One at a time | واحدًا تلو الآخر |
| dashboard | `header.decide.pick` | Choose a decision | اختر قرارًا |
| dashboard | `header.decide.since` | Waiting :time | بالانتظار منذ :time |
| dashboard | `header.decide.more` | {1} 1 more in the inbox\|[2,*] :count more in the inbox | لا مزيد في صندوق المهام\|واحد آخر في صندوق المهام\|اثنان آخران في صندوق المهام\|:count أخرى في صندوق المهام\|:count آخر في صندوق المهام\|:count آخر في صندوق المهام |
| dashboard | `header.decide.verb.decide` | Review leave | راجع الإجازة |
| dashboard | `header.decide.verb.review` | Check the decision | راجع القرار |
| dashboard | `header.decide.verb.approve` | Review the change | راجع التعديل |
| dashboard_clock | `work.progress` | Working day progress | التقدّم في يوم العمل |
| dashboard_clock | `work.done` | At work for :time | أمضيت في الدوام :time |
| dashboard_clock | `work.not-started` | Not started yet | لم يبدأ الدوام بعد |
| dashboard_clock | `work.over` | Your day is over | انتهى دوامك اليوم |
| dashboard_clock | `work.hours` | Hours :hours | الدوام :hours |
| dashboard_clock | `work.holiday` | Official holiday: :name | عطلة رسمية: :name |
| dashboard_clock | `presence.title` | Online now | متصلون الآن |
| dashboard_clock | `presence.count` | :n of :all | :n من :all |
| dashboard_clock | `presence.none` | No one else from your team is online. | لا أحد غيرك من فريقك متصل الآن. |
| dashboard_clock | `presence.more` | +:n | +:n |
| dashboard_clock | `presence.more-label` | and :n more | و:n آخرون |
| mobile | `bar.new-label` | New: start something | جديد: ابدأ شيئًا جديدًا |
| mobile | `bar.new-title` | Start something new | ابدأ شيئًا جديدًا |
| mobile | `bar.more` | More | المزيد |
| mobile | `bar.more-label` | More: every page | المزيد: كل الصفحات |
| mobile | `bar.more-title` | All pages | كل الصفحات |
| mobile | `bar.close` | Close | إغلاق |
| mobile | `bar.lead` | Choose the four shortcuts at the bottom of your phone screen: two before the + in the middle and two after it. More, at the end, opens every page. | اختر الاختصارات الأربعة أسفل شاشة هاتفك: اثنان قبل زر + في المنتصف واثنان بعده. زر المزيد في الطرف يفتح كل الصفحات. |
| mobile | `bar.place-before` | Place :number, before the + | المكان :number، قبل + |
| mobile | `bar.place-after` | Place :number, after the + | المكان :number، بعد + |
| mobile | `bar.more-place` | The + (start something new) and More (every page) are always there. | زر + (ابدأ شيئًا جديدًا) وزر المزيد (كل الصفحات) موجودان دائمًا. |
| examples | `leave_reason.0` | Family visit in Homs | زيارة عائلية في حمص |
| examples | `leave_reason.1` | My brother's wedding | عرس أخي |
| examples | `leave_reason.2` | Medical appointment in Damascus | موعد طبي في دمشق |
| examples | `leave_reason.3` | Moving to a new house | الانتقال إلى بيت جديد |
| examples | `leave_reason.4` | University exam | امتحان في الجامعة |
| examples | `first_name.0` | Rami | رامي |
| examples | `first_name.1` | Joumana | جمانة |
| examples | `first_name.2` | George | جورج |
| examples | `first_name.3` | Mirna | ميرنا |
| examples | `first_name.4` | Fadi | فادي |
| examples | `last_name.0` | Khoury | خوري |
| examples | `last_name.1` | Haddad | حداد |
| examples | `last_name.2` | Abboud | عبود |
| examples | `last_name.3` | Nasr | نصر |
| examples | `father_name.0` | Youssef | يوسف |
| examples | `father_name.1` | Elias | إلياس |
| examples | `father_name.2` | Samir | سمير |
| examples | `father_name.3` | Nabil | نبيل |
| examples | `mother_name.0` | Mary | ماري |
| examples | `mother_name.1` | Suad | سعاد |
| examples | `mother_name.2` | Nawal | نوال |
| examples | `mother_name.3` | Hanan | حنان |
| examples | `phone.0` | 0933000111 | 0933000111 |
| examples | `phone.1` | 0944000222 | 0944000222 |
| examples | `phone.2` | 0955000333 | 0955000333 |
| examples | `personal_email.0` | rami.k@example.com | rami.k@example.com |
| examples | `personal_email.1` | joumana.haddad@example.org | joumana.haddad@example.org |
| examples | `news.title.0` | Study Zone opens a new class in Aleppo | ستادي زون يفتتح صفاً جديداً في حلب |
| examples | `news.title.1` | Staff day on 15 November | يوم الموظفين في 15 تشرين الثاني |
| examples | `news.title.2` | Christmas activities for the children in Homs | نشاطات الميلاد للأطفال في حمص |
| examples | `news.description.0` | All staff are invited to the yearly staff day. The bus leaves the centre at 8:00. | جميع الموظفين مدعوون إلى يوم الموظفين السنوي. ينطلق الباص من المركز الساعة 8:00. |
| examples | `news.description.1` | Registration for the new LEAP group is open until the end of the month. | التسجيل في مجموعة ليب الجديدة مفتوح حتى نهاية الشهر. |
| examples | `global_search.0` | Leave requests | طلبات الإجازة |
| examples | `global_search.1` | Contracts ending this month | عقود تنتهي هذا الشهر |
| examples | `global_search.2` | Aleppo | حلب |
| examples | `global_search.3` | New employee | موظف جديد |
| examples | `global_search.4` | Org chart | الهيكل التنظيمي |
| examples | `hints.leave_reason` | A few words are enough. Your manager and HR see this. | تكفي كلمات قليلة. يراها مديرك وقسم الموارد البشرية. |
| examples | `hints.name` | As written on the ID card. | كما هو مكتوب في البطاقة الشخصية. |
| examples | `hints.phone` | 10 digits, starting with 09. | 10 أرقام، تبدأ بـ 09. |
| examples | `hints.news_title` | Short and clear: it is the headline on the news card. | قصير وواضح: هو عنوان بطاقة الخبر. |
| examples | `hints.news_description` | Say what, when and where in two or three sentences. | اذكر ماذا ومتى وأين في جملتين أو ثلاث. |
