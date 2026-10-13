<?php

namespace Database\Seeders;

use App\Models\Position;
use Illuminate\Database\Seeder;

/**
 * Fills `qualifications` and `responsibilities` for existing positions, matched
 * by English title. Only blank or placeholder values (shorter than
 * PLACEHOLDER_LENGTH) are overwritten, so text entered through the UI survives
 * re-runs. Run with: php artisan db:seed --class=PositionDetailsSeeder
 */
class PositionDetailsSeeder extends Seeder
{
    private const PLACEHOLDER_LENGTH = 10;

    public function run(): void
    {
        $details = collect($this->details())->keyBy(fn ($_, $title) => $this->normalize($title));
        $filled = 0;
        $unmatched = [];

        foreach (Position::query()->orderBy('id')->get(['id', 'title_en', 'qualifications', 'responsibilities']) as $position) {
            $detail = $details->get($this->normalize($position->title_en));

            if (! $detail) {
                $unmatched[] = "#{$position->id} {$position->title_en}";

                continue;
            }

            $changes = [];

            foreach (['qualifications', 'responsibilities'] as $field) {
                if ($this->isPlaceholder($position->{$field})) {
                    $changes[$field] = $this->toList($detail[$field]);
                }
            }

            if ($changes) {
                // Query update: a seeding pass shouldn't write one activity-log row per position.
                Position::whereKey($position->id)->update($changes);
                $filled++;
            }
        }

        $this->command?->info("Filled {$filled} position(s).");

        if ($unmatched) {
            $this->command?->warn('No details defined for: '.implode(', ', $unmatched));
        }
    }

    private function normalize(string $title): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($title)));
    }

    private function isPlaceholder(?string $value): bool
    {
        return mb_strlen(trim((string) $value)) < self::PLACEHOLDER_LENGTH;
    }

    /** @param  list<string>  $items */
    private function toList(array $items): string
    {
        return implode("\n", array_map(fn ($item) => "- {$item}", $items));
    }

    /**
     * @return array<string, array{qualifications: list<string>, responsibilities: list<string>}>
     */
    private function details(): array
    {
        return [
            // ------------------------------------------------------------
            // Executive leadership
            // ------------------------------------------------------------
            'Chief Executive Officer' => [
                'qualifications' => [
                    "Master's degree in management, development studies, or a related field",
                    'At least 10 years of senior leadership experience in NGOs or humanitarian organizations',
                    'Proven record in strategic planning, fundraising, and donor relations',
                    'Strong financial oversight and governance knowledge',
                    'Excellent communication and representation skills in Arabic and English',
                ],
                'responsibilities' => [
                    'Set the organization\'s vision, strategy, and annual plans with the board',
                    'Lead the senior management team and oversee all programs and departments',
                    'Represent the organization before donors, partners, churches, and authorities',
                    'Ensure financial sustainability, compliance, and sound governance',
                    'Approve budgets, key policies, and senior appointments',
                ],
            ],
            'Deputy Chief Executive Officer' => [
                'qualifications' => [
                    "Master's degree in management, development, or a related field",
                    'At least 8 years of management experience, including multi-program oversight',
                    'Strong operational, planning, and people-management skills',
                    'Experience in donor reporting and compliance',
                    'Fluency in Arabic and English',
                ],
                'responsibilities' => [
                    'Support the CEO in leading the organization and deputize in their absence',
                    'Oversee day-to-day operations across cities and centers',
                    'Coordinate program managers and support departments to meet annual targets',
                    'Monitor performance, risks, and compliance with internal policies',
                    'Lead cross-department initiatives assigned by the CEO',
                ],
            ],
            'Second Deputy Chief Executive Officer' => [
                'qualifications' => [
                    "Master's degree in management, development, or a related field",
                    'At least 8 years of management experience in NGOs',
                    'Strong operational and organizational development skills',
                    'Experience in partnership management and reporting',
                    'Fluency in Arabic and English',
                ],
                'responsibilities' => [
                    'Support the CEO and Deputy CEO in executive management',
                    'Oversee the departments and programs delegated by the CEO',
                    'Drive organizational development, systems, and policy updates',
                    'Follow up on strategic plan implementation and report progress',
                    'Represent the organization in meetings and coordination forums as assigned',
                ],
            ],
            'Consaltant' => [
                'qualifications' => [
                    "Bachelor's degree in a relevant field; a master's degree is an advantage",
                    'At least 7 years of expertise in the area of consultancy',
                    'Proven track record of advisory work with NGOs or similar organizations',
                    'Strong analytical, writing, and presentation skills',
                    'Fluency in Arabic and English',
                ],
                'responsibilities' => [
                    'Provide expert advice to management within the agreed scope of work',
                    'Assess current practices and recommend improvements',
                    'Prepare studies, reports, policies, or tools as requested',
                    'Build the capacity of staff through coaching and workshops',
                    'Deliver agreed outputs on time and to quality standards',
                ],
            ],

            // ------------------------------------------------------------
            // Program & project management
            // ------------------------------------------------------------
            'Program Manager' => [
                'qualifications' => [
                    "Bachelor's degree in social sciences, development, management, or a related field",
                    'At least 5 years of experience managing humanitarian or development programs',
                    'Strong knowledge of project cycle management and logical frameworks',
                    'Experience with budgeting, donor reporting, and team leadership',
                    'Fluency in Arabic and good English',
                ],
                'responsibilities' => [
                    'Lead the design, planning, and implementation of the program',
                    'Supervise project managers and ensure activities meet targets and quality standards',
                    'Manage the program budget and follow up on spending',
                    'Prepare program reports and proposals for donors and management',
                    'Coordinate with partners, centers, and support departments',
                ],
            ],
            'Relief Program Manager' => [
                'qualifications' => [
                    "Bachelor's degree in social sciences, management, or a related field",
                    'At least 5 years of experience in relief, emergency response, or distributions',
                    'Knowledge of humanitarian principles and beneficiary targeting',
                    'Experience in supply chain coordination and budget management',
                    'Fluency in Arabic and good English',
                ],
                'responsibilities' => [
                    'Plan and oversee relief projects and distributions across cities',
                    'Ensure fair beneficiary selection and accountability to affected people',
                    'Coordinate procurement and logistics with the logistics team',
                    'Manage relief budgets and prepare donor reports',
                    'Supervise relief staff and field teams',
                ],
            ],
            'Projects and Fundraising Manager' => [
                'qualifications' => [
                    "Bachelor's degree in management, development, or a related field",
                    'At least 5 years of experience in proposal writing and fundraising',
                    'Excellent English writing skills',
                    'Knowledge of donor requirements, budgeting, and logical frameworks',
                    'Strong networking and relationship-management skills',
                ],
                'responsibilities' => [
                    'Identify funding opportunities and build relationships with donors',
                    'Lead proposal writing and budget preparation with program teams',
                    'Track grant agreements, deadlines, and reporting obligations',
                    'Review donor reports for quality and compliance',
                    'Develop and maintain the organization\'s fundraising strategy',
                ],
            ],
            'Project Manager' => [
                'qualifications' => [
                    "Bachelor's degree in a field relevant to the project",
                    'At least 3 years of experience in project management',
                    'Good knowledge of planning, monitoring, and reporting',
                    'Leadership and team-management skills',
                    'Good command of computer skills and English',
                ],
                'responsibilities' => [
                    'Plan and implement project activities according to the approved plan',
                    'Supervise the project team and distribute tasks',
                    'Follow up on the project budget and spending',
                    'Prepare periodic progress reports',
                    'Coordinate with the program manager, center, and support departments',
                ],
            ],
            'Project Assistant Manager' => [
                'qualifications' => [
                    "Bachelor's degree in a relevant field",
                    'At least 2 years of experience in project implementation',
                    'Good organizational and follow-up skills',
                    'Good computer skills (MS Office)',
                    'Good communication skills',
                ],
                'responsibilities' => [
                    'Support the project manager in planning and implementing activities',
                    'Follow up on the daily work of the project team',
                    'Keep project records, attendance, and documentation up to date',
                    'Help prepare reports and data for monitoring',
                    'Act for the project manager when assigned',
                ],
            ],
            'Project Coordinator' => [
                'qualifications' => [
                    "Bachelor's degree in a relevant field",
                    'At least 2 years of experience in coordinating projects or activities',
                    'Strong organizational and time-management skills',
                    'Good computer skills (MS Office)',
                    'Good communication and teamwork skills',
                ],
                'responsibilities' => [
                    'Coordinate project activities, schedules, and logistics',
                    'Communicate with beneficiaries, trainers, and partners',
                    'Follow up on the implementation timeline and flag delays',
                    'Collect data and documentation for reporting',
                    'Support the project manager in daily management',
                ],
            ],
            'Project Officer' => [
                'qualifications' => [
                    "Bachelor's degree in a relevant field",
                    'At least 1 year of experience in NGO projects',
                    'Good organizational and reporting skills',
                    'Good computer skills',
                    'Ability to work with diverse communities',
                ],
                'responsibilities' => [
                    'Implement assigned project activities',
                    'Register and follow up with beneficiaries',
                    'Prepare activity reports and attendance records',
                    'Coordinate with the project team and center staff',
                    'Report challenges and suggest solutions to the project manager',
                ],
            ],
            'Project Team Member' => [
                'qualifications' => [
                    'University degree or diploma in a relevant field',
                    'Previous experience in community or NGO work is an advantage',
                    'Good communication and teamwork skills',
                    'Basic computer skills',
                    'Commitment to the organization\'s values',
                ],
                'responsibilities' => [
                    'Carry out the project tasks assigned by the project manager',
                    'Support the preparation and running of activities',
                    'Deal respectfully with beneficiaries and keep their information confidential',
                    'Keep required records and documentation',
                    'Take part in team meetings and trainings',
                ],
            ],
            'Field Officer' => [
                'qualifications' => [
                    "Bachelor's degree in social sciences or a related field",
                    'At least 2 years of field experience in humanitarian work',
                    'Good knowledge of the local community',
                    'Assessment, interviewing, and reporting skills',
                    'Ability to travel within the city and work in the field',
                ],
                'responsibilities' => [
                    'Conduct field visits and needs assessments',
                    'Verify beneficiary eligibility and data',
                    'Supervise field officer assistants',
                    'Support the organization of distributions and field activities',
                    'Prepare field reports for the project manager',
                ],
            ],
            'Field Officer Assistant' => [
                'qualifications' => [
                    'University degree or diploma',
                    'Previous field work experience is an advantage',
                    'Good communication skills with the community',
                    'Basic computer skills',
                    'Ability to work in the field',
                ],
                'responsibilities' => [
                    'Support the field officer in field visits and assessments',
                    'Collect and enter beneficiary data accurately',
                    'Help organize distributions and activities',
                    'Communicate with beneficiaries about schedules and services',
                    'Report field observations to the field officer',
                ],
            ],
            'Distribution Coordinator' => [
                'qualifications' => [
                    'University degree in a relevant field',
                    'At least 2 years of experience in distributions or logistics',
                    'Strong organizational and crowd-management skills',
                    'Good computer skills (Excel)',
                    'Knowledge of accountability to affected people',
                ],
                'responsibilities' => [
                    'Plan and organize distribution days and sites',
                    'Coordinate with logistics on stock and delivery',
                    'Ensure beneficiary lists are verified and recipients sign for aid',
                    'Handle complaints and feedback during distributions',
                    'Prepare distribution reports and reconcile quantities',
                ],
            ],
            'Case Manager' => [
                'qualifications' => [
                    "Bachelor's degree in social work, psychology, or a related field",
                    'At least 2 years of experience in case management',
                    'Knowledge of protection principles and referral pathways',
                    'Strong listening and documentation skills',
                    'Commitment to confidentiality',
                ],
                'responsibilities' => [
                    'Assess beneficiaries\' needs and develop case plans',
                    'Provide follow-up and refer cases to relevant services',
                    'Keep case files accurate and confidential',
                    'Coordinate with partners and service providers',
                    'Report on cases and outcomes',
                ],
            ],
            'Activity Facilitator' => [
                'qualifications' => [
                    'University degree or diploma in education, social sciences, or a related field',
                    'Experience in facilitating activities for children, youth, or adults',
                    'Creativity and good communication skills',
                    'Ability to manage groups',
                    'Patience and commitment',
                ],
                'responsibilities' => [
                    'Prepare and facilitate recreational and educational activities',
                    'Ensure a safe and welcoming environment for participants',
                    'Keep attendance and activity records',
                    'Observe participants and report concerns to the supervisor',
                    'Take part in planning activities with the team',
                ],
            ],
            'Psychosocial Support' => [
                'qualifications' => [
                    "Bachelor's degree in psychology, social work, or a related field",
                    'At least 2 years of experience in psychosocial support',
                    'Knowledge of PFA and group support techniques',
                    'Strong listening and empathy skills',
                    'Commitment to confidentiality',
                ],
                'responsibilities' => [
                    'Provide individual and group psychosocial support sessions',
                    'Identify cases needing specialized care and refer them',
                    'Design psychosocial activities for different age groups',
                    'Keep confidential session records',
                    'Support staff wellbeing initiatives when needed',
                ],
            ],
            'Spiritual Care Specialist' => [
                'qualifications' => [
                    'Degree or formal training in theology or pastoral care',
                    'Experience in spiritual care or church ministry',
                    'Strong listening and counseling skills',
                    'Commitment to the organization\'s Christian values',
                    'Respect for people of all backgrounds',
                ],
                'responsibilities' => [
                    'Provide spiritual care and support to staff and beneficiaries who request it',
                    'Organize devotions, prayer times, and spiritual activities',
                    'Coordinate with local churches and partners',
                    'Support staff care and wellbeing',
                    'Keep pastoral conversations confidential',
                ],
            ],
            'Companion' => [
                'qualifications' => [
                    'Secondary school certificate at minimum',
                    'Experience in caring for or accompanying people is an advantage',
                    'Patience, kindness, and responsibility',
                    'Good communication skills',
                    'Physical ability to accompany participants',
                ],
                'responsibilities' => [
                    'Accompany beneficiaries during activities, trips, and appointments',
                    'Ensure the safety and comfort of accompanied persons',
                    'Help participants take part in activities',
                    'Report any incident or concern to the supervisor',
                    'Follow the organization\'s safeguarding policy',
                ],
            ],

            // ------------------------------------------------------------
            // Teachers & trainers
            // ------------------------------------------------------------
            'Coach' => [
                'qualifications' => [
                    'Certification or experience in coaching or training',
                    'At least 2 years of experience in the relevant field',
                    'Good communication and motivational skills',
                    'Ability to work with youth and adults',
                    'Commitment and punctuality',
                ],
                'responsibilities' => [
                    'Deliver coaching sessions according to the agreed plan',
                    'Follow up on participants\' progress and give feedback',
                    'Prepare session materials',
                    'Keep attendance records',
                    'Report on outcomes to the project manager',
                ],
            ],
            'English Teacher' => [
                'qualifications' => [
                    "Bachelor's degree in English literature or language",
                    'At least 2 years of teaching experience',
                    'Knowledge of modern language-teaching methods',
                    'Good classroom management skills',
                    'A TEFL/CELTA certificate is an advantage',
                ],
                'responsibilities' => [
                    'Teach English courses according to the approved curriculum',
                    'Prepare lessons, materials, and assessments',
                    'Follow up on students\' progress and attendance',
                    'Create an encouraging learning environment',
                    'Report on class results to the project manager',
                ],
            ],
            'Engineering Teacher' => [
                'qualifications' => [
                    "Bachelor's degree in engineering",
                    'Teaching or training experience',
                    'Strong knowledge of the subjects taught',
                    'Good presentation and explanation skills',
                    'Good computer skills',
                ],
                'responsibilities' => [
                    'Teach engineering subjects according to the curriculum',
                    'Prepare lessons, exercises, and exams',
                    'Help students with questions and difficulties',
                    'Follow up on attendance and results',
                    'Report on students\' progress',
                ],
            ],
            'Architecture Teacher' => [
                'qualifications' => [
                    "Bachelor's degree in architecture",
                    'Teaching or training experience',
                    'Proficiency in architectural design software (AutoCAD, Revit, etc.)',
                    'Good presentation and explanation skills',
                    'Commitment and punctuality',
                ],
                'responsibilities' => [
                    'Teach architecture courses according to the curriculum',
                    'Prepare lessons, projects, and assessments',
                    'Guide students in design projects',
                    'Follow up on attendance and results',
                    'Report on students\' progress',
                ],
            ],
            'General science Teacher' => [
                'qualifications' => [
                    "Bachelor's degree in science (physics, chemistry, or biology)",
                    'At least 2 years of teaching experience',
                    'Good classroom management skills',
                    'Ability to simplify scientific concepts',
                    'Commitment and punctuality',
                ],
                'responsibilities' => [
                    'Teach general science according to the curriculum',
                    'Prepare lessons, experiments, and assessments',
                    'Follow up on students\' progress and attendance',
                    'Support students with learning difficulties',
                    'Report on class results',
                ],
            ],
            'Medical Science Teacher' => [
                'qualifications' => [
                    "Bachelor's degree in medicine, pharmacy, nursing, or medical sciences",
                    'Teaching or training experience',
                    'Strong knowledge of the subjects taught',
                    'Good presentation skills',
                    'Commitment and punctuality',
                ],
                'responsibilities' => [
                    'Teach medical science subjects according to the curriculum',
                    'Prepare lessons, materials, and exams',
                    'Help students with questions and difficulties',
                    'Follow up on attendance and results',
                    'Report on students\' progress',
                ],
            ],
            'Media Teacher' => [
                'qualifications' => [
                    "Bachelor's degree in media, journalism, or a related field",
                    'Practical experience in media production',
                    'Teaching or training experience',
                    'Proficiency in relevant media tools and software',
                    'Good presentation skills',
                ],
                'responsibilities' => [
                    'Teach media courses according to the curriculum',
                    'Prepare lessons and practical exercises',
                    'Guide students in media projects',
                    'Follow up on attendance and results',
                    'Report on students\' progress',
                ],
            ],
            'Entrepreneurship Teacher' => [
                'qualifications' => [
                    "Bachelor's degree in business administration, economics, or a related field",
                    'Practical experience in entrepreneurship or small business',
                    'Teaching or training experience',
                    'Good presentation and mentoring skills',
                    'Commitment and punctuality',
                ],
                'responsibilities' => [
                    'Teach entrepreneurship courses according to the curriculum',
                    'Guide participants in developing business ideas and plans',
                    'Prepare lessons, case studies, and assessments',
                    'Follow up on attendance and results',
                    'Report on participants\' progress',
                ],
            ],
            'ICDL Trainer' => [
                'qualifications' => [
                    'University degree in IT or a related field',
                    'ICDL trainer certification or equivalent',
                    'At least 1 year of training experience',
                    'Strong command of computer basics and MS Office',
                    'Good presentation skills',
                ],
                'responsibilities' => [
                    'Deliver ICDL courses according to the official modules',
                    'Prepare trainees for ICDL exams',
                    'Prepare training materials and exercises',
                    'Follow up on attendance and trainees\' progress',
                    'Keep lab equipment in good use',
                ],
            ],
            'Robotics Trainer' => [
                'qualifications' => [
                    'University degree in mechatronics, electronics, computer engineering, or a related field',
                    'Practical experience in robotics and programming (Arduino, etc.)',
                    'Experience in training children or youth',
                    'Good presentation skills',
                    'Creativity and patience',
                ],
                'responsibilities' => [
                    'Deliver robotics and programming sessions',
                    'Prepare projects, kits, and training materials',
                    'Guide participants in building and programming robots',
                    'Look after robotics equipment',
                    'Follow up on attendance and participants\' progress',
                ],
            ],
            'Marketing Staff' => [
                'qualifications' => [
                    'University degree or diploma in marketing, business, or a related field',
                    'Experience in marketing or sales is an advantage',
                    'Good communication and persuasion skills',
                    'Good knowledge of social media',
                    'Teamwork skills',
                ],
                'responsibilities' => [
                    'Promote the center\'s courses and services',
                    'Communicate with the community to reach target groups',
                    'Help prepare marketing materials and campaigns',
                    'Register interested people and follow up with them',
                    'Report on marketing results',
                ],
            ],

            // ------------------------------------------------------------
            // Communication
            // ------------------------------------------------------------
            'Communication Manager / Syria' => [
                'qualifications' => [
                    "Bachelor's degree in media, communication, or marketing",
                    'At least 5 years of experience in communication, including team leadership',
                    'Strong writing skills in Arabic and English',
                    'Experience in social media strategy and brand management',
                    'Knowledge of design and media production',
                ],
                'responsibilities' => [
                    'Develop and implement the communication strategy for Syria',
                    'Lead and supervise the communication team',
                    'Ensure brand consistency across all materials and platforms',
                    'Oversee content for social media, reports, and donors',
                    'Manage relations with media and partners',
                ],
            ],
            'Associate Communication Manager' => [
                'qualifications' => [
                    "Bachelor's degree in media, communication, or a related field",
                    'At least 3 years of experience in communication',
                    'Good writing skills in Arabic and English',
                    'Experience in social media management',
                    'Organizational and teamwork skills',
                ],
                'responsibilities' => [
                    'Support the communication manager in implementing the communication plan',
                    'Coordinate the work of designers, photographers, and videographers',
                    'Write and review content for platforms and publications',
                    'Follow up on the publishing schedule',
                    'Prepare communication reports and analytics',
                ],
            ],
            'Communication Officer / Designer' => [
                'qualifications' => [
                    'University degree in graphic design, fine arts, or a related field',
                    'At least 2 years of design experience',
                    'Proficiency in Adobe Photoshop, Illustrator, and InDesign',
                    'A strong portfolio',
                    'Creativity and attention to detail',
                ],
                'responsibilities' => [
                    'Design visual materials for social media, reports, and events',
                    'Apply the organization\'s visual identity guidelines',
                    'Prepare print-ready files',
                    'Work with the communication team on campaigns',
                    'Keep an organized design archive',
                ],
            ],
            'Communication Officer / Photographer' => [
                'qualifications' => [
                    'Degree or training in photography or media',
                    'At least 2 years of photography experience',
                    'Proficiency in photo editing (Lightroom, Photoshop)',
                    'A strong portfolio',
                    'Knowledge of ethical photography and consent',
                ],
                'responsibilities' => [
                    'Photograph activities, events, and success stories',
                    'Edit and archive photos',
                    'Obtain informed consent from people photographed',
                    'Provide photos for publications and donor reports',
                    'Maintain photography equipment',
                ],
            ],
            'Communication Officer / Videographer' => [
                'qualifications' => [
                    'Degree or training in media or video production',
                    'At least 2 years of video production experience',
                    'Proficiency in editing software (Premiere, After Effects, etc.)',
                    'A strong portfolio',
                    'Storytelling skills',
                ],
                'responsibilities' => [
                    'Film activities, events, and interviews',
                    'Edit and produce videos for platforms and donors',
                    'Obtain informed consent from people filmed',
                    'Archive video material',
                    'Maintain video equipment',
                ],
            ],
            'linkedIn presence specialist' => [
                'qualifications' => [
                    'University degree in media, marketing, or a related field',
                    'Experience managing professional LinkedIn pages',
                    'Excellent English writing skills',
                    'Knowledge of LinkedIn analytics and best practices',
                    'Networking skills',
                ],
                'responsibilities' => [
                    'Manage and grow the organization\'s LinkedIn presence',
                    'Write and publish professional content',
                    'Engage with partners, donors, and the professional community',
                    'Track performance and report on analytics',
                    'Support staff in strengthening their professional profiles',
                ],
            ],
            'Public Relation Officer' => [
                'qualifications' => [
                    'University degree in media, public relations, or a related field',
                    'At least 2 years of experience in public relations',
                    'Excellent communication and networking skills',
                    'Good knowledge of the local community and institutions',
                    'Good English is an advantage',
                ],
                'responsibilities' => [
                    'Build and maintain relations with local institutions, churches, and partners',
                    'Represent the organization in local meetings and events',
                    'Arrange official visits and events',
                    'Handle the required approvals and correspondence',
                    'Report on relations and opportunities',
                ],
            ],

            // ------------------------------------------------------------
            // MEAL
            // ------------------------------------------------------------
            'MEAL Manager' => [
                'qualifications' => [
                    "Bachelor's degree in statistics, social sciences, or a related field",
                    'At least 5 years of experience in monitoring and evaluation',
                    'Strong knowledge of MEAL frameworks, indicators, and data tools',
                    'Experience with data analysis software (Excel, KoBo, Power BI, etc.)',
                    'Fluency in Arabic and good English',
                ],
                'responsibilities' => [
                    'Develop the MEAL system and tools for all programs',
                    'Lead the MEAL team and build program staff capacity',
                    'Oversee data collection, analysis, and quality',
                    'Manage the complaints and feedback mechanism',
                    'Produce evaluation reports and lessons learned',
                ],
            ],
            'M&E Officer' => [
                'qualifications' => [
                    "Bachelor's degree in statistics, social sciences, or a related field",
                    'At least 2 years of experience in monitoring and evaluation',
                    'Experience with data collection tools (KoBo, ODK)',
                    'Good data analysis skills (Excel)',
                    'Attention to detail',
                ],
                'responsibilities' => [
                    'Collect and verify project data',
                    'Conduct monitoring visits and satisfaction surveys',
                    'Track indicators and prepare data reports',
                    'Receive and follow up on beneficiary feedback',
                    'Support the MEAL manager in evaluations',
                ],
            ],

            // ------------------------------------------------------------
            // Finance
            // ------------------------------------------------------------
            'Senior Financial Manager' => [
                'qualifications' => [
                    "Bachelor's degree in accounting or finance; a professional certificate is an advantage",
                    'At least 8 years of financial management experience, preferably in NGOs',
                    'Strong knowledge of accounting standards and donor financial rules',
                    'Experience in budgeting, audits, and financial reporting',
                    'Proficiency in accounting software and Excel',
                ],
                'responsibilities' => [
                    'Lead the organization\'s financial management and finance team',
                    'Prepare and monitor the annual budget',
                    'Ensure compliance with financial policies and donor requirements',
                    'Coordinate internal and external audits',
                    'Provide financial reports and analysis to management',
                ],
            ],
            'Financial Manager' => [
                'qualifications' => [
                    "Bachelor's degree in accounting or finance",
                    'At least 5 years of financial experience, preferably in NGOs',
                    'Knowledge of donor financial requirements',
                    'Proficiency in accounting software and Excel',
                    'Strong analytical skills and integrity',
                ],
                'responsibilities' => [
                    'Manage accounting, payments, and cash flow',
                    'Supervise accountants and review their work',
                    'Prepare financial and donor reports',
                    'Follow up on budgets and spending',
                    'Prepare for audits and apply financial policies',
                ],
            ],
            'Senior Accountant' => [
                'qualifications' => [
                    "Bachelor's degree in accounting",
                    'At least 4 years of accounting experience',
                    'Proficiency in accounting software and Excel',
                    'Good knowledge of reconciliations and financial closing',
                    'Accuracy and integrity',
                ],
                'responsibilities' => [
                    'Review accounting entries and supporting documents',
                    'Prepare monthly closing and bank reconciliations',
                    'Supervise and support accountants',
                    'Prepare financial reports for projects',
                    'Support audits',
                ],
            ],
            'Accountant' => [
                'qualifications' => [
                    "Bachelor's degree in accounting or finance",
                    'At least 2 years of accounting experience',
                    'Proficiency in accounting software and Excel',
                    'Knowledge of documentation and archiving',
                    'Accuracy and integrity',
                ],
                'responsibilities' => [
                    'Record financial transactions and keep supporting documents',
                    'Manage petty cash and payments',
                    'Prepare bank and cash reconciliations',
                    'Prepare project financial reports',
                    'Archive financial documents properly',
                ],
            ],

            // ------------------------------------------------------------
            // Support services
            // ------------------------------------------------------------
            'HR Generalist' => [
                'qualifications' => [
                    "Bachelor's degree in human resources, business administration, or a related field",
                    'At least 3 years of HR experience',
                    'Knowledge of Syrian labor law',
                    'Experience in recruitment, contracts, and personnel files',
                    'Good computer skills and confidentiality',
                ],
                'responsibilities' => [
                    'Manage recruitment from job posting to onboarding',
                    'Prepare contracts and maintain personnel files',
                    'Follow up on attendance, leave, and HR records',
                    'Apply HR policies and answer staff inquiries',
                    'Support performance appraisals and training',
                ],
            ],
            'IT Manager' => [
                'qualifications' => [
                    "Bachelor's degree in IT, computer science, or software engineering",
                    'At least 5 years of experience in IT or software development',
                    'Experience leading technical teams and projects',
                    'Knowledge of networks, systems, and information security',
                    'Good English',
                ],
                'responsibilities' => [
                    'Lead the IT and software team',
                    'Plan and oversee the development of internal systems',
                    'Manage infrastructure, networks, and information security',
                    'Set IT policies and procedures',
                    'Evaluate and procure technical solutions',
                ],
            ],
            'IT Specialist' => [
                'qualifications' => [
                    'University degree in IT or a related field',
                    'At least 2 years of technical support experience',
                    'Knowledge of networks, hardware, and operating systems',
                    'Problem-solving skills',
                    'Good English',
                ],
                'responsibilities' => [
                    'Provide technical support to staff',
                    'Maintain computers, printers, and networks',
                    'Install and update software',
                    'Manage user accounts and backups',
                    'Keep an inventory of IT equipment',
                ],
            ],
            'Legal Affairs Consultant' => [
                'qualifications' => [
                    'Law degree and membership of the bar association',
                    'At least 5 years of legal experience',
                    'Good knowledge of Syrian law, especially labor and association law',
                    'Experience in drafting and reviewing contracts',
                    'Integrity and confidentiality',
                ],
                'responsibilities' => [
                    'Provide legal advice to management',
                    'Draft and review contracts and agreements',
                    'Follow up on licenses and official procedures',
                    'Represent the organization before official bodies when needed',
                    'Ensure the organization\'s compliance with the law',
                ],
            ],
            'Logistic Officer' => [
                'qualifications' => [
                    'University degree in business administration or a related field',
                    'At least 2 years of experience in procurement or logistics',
                    'Knowledge of procurement procedures and competitive bidding',
                    'Negotiation and follow-up skills',
                    'Good computer skills (Excel)',
                ],
                'responsibilities' => [
                    'Carry out procurement according to the procurement policy',
                    'Collect quotations and compare offers',
                    'Manage warehouses, stock, and asset records',
                    'Coordinate transportation and deliveries',
                    'Keep procurement documents complete',
                ],
            ],
            'Administrator' => [
                'qualifications' => [
                    'University degree or diploma in administration or a related field',
                    'At least 2 years of administrative experience',
                    'Good organizational and follow-up skills',
                    'Good computer skills (MS Office)',
                    'Good communication skills',
                ],
                'responsibilities' => [
                    'Supervise the daily administration of the center',
                    'Organize schedules, rooms, and center resources',
                    'Receive visitors and handle correspondence',
                    'Follow up on attendance and administrative records',
                    'Coordinate with projects and support departments',
                ],
            ],
            'Center keeper' => [
                'qualifications' => [
                    'Basic education',
                    'Experience in cleaning or housekeeping is an advantage',
                    'Honesty and responsibility',
                    'Good physical fitness',
                    'Punctuality',
                ],
                'responsibilities' => [
                    'Keep the center clean and tidy',
                    'Prepare rooms before activities',
                    'Take care of center furniture and supplies',
                    'Report maintenance needs',
                    'Help with hospitality during events',
                ],
            ],
        ];
    }
}
