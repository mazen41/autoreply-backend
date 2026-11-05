<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Package;

class PackageSeeder extends Seeder
{
    /**
     * Keep the public pricing catalog and newly created environments aligned.
     * AI credit allowances are debited by provider cost in the AI credit wallet.
     */
    public static function definitions(): array
    {
        return [
            'Free' => [
                'name_ar' => 'مجاني',
                'description' => 'Start using Nazeer Business at no cost.',
                'description_ar' => 'ابدأ رحلتك مع نظير بزنس بدون أي تكلفة.',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'ai_replies_limit' => 1000,
                'ai_credits_monthly' => 1000,
                'channels_limit' => -1,
                'outbound_messages_limit' => -1,
                'max_team_members' => 1,
                'tools_limit' => -1,
                'blog_posts_limit' => -1,
                'features' => ['Unlimited channel and store connections', 'Unified inbox', 'Unlimited workflows and sequences', 'Basic AI assistant', '1,000 AI credits per month', '1 team member'],
                'features_ar' => ['ربط غير محدود للقنوات والمتاجر', 'صندوق وارد موحد', 'سير عمل وتسلسلات غير محدودة', 'مساعد ذكاء اصطناعي أساسي', '1,000 رصيد AI شهرياً', 'عضو واحد في الفريق'],
                'is_popular' => false,
                'is_active' => true,
                'sort_order' => 1,
            ],
            'Starter' => [
                'name_ar' => 'المبتدئة',
                'description' => 'Everything you need to start automating customer conversations.',
                'description_ar' => 'كل ما تحتاجه للبدء في أتمتة التواصل مع عملائك.',
                'price_monthly' => 99,
                'price_yearly' => 1188,
                'ai_replies_limit' => 10000,
                'ai_credits_monthly' => 10000,
                'channels_limit' => -1,
                'outbound_messages_limit' => -1,
                'max_team_members' => 5,
                'tools_limit' => -1,
                'blog_posts_limit' => -1,
                'features' => ['Unlimited channel and store connections', 'Unified inbox', 'Unlimited workflows and sequences', 'AI assistant and knowledge base', 'Marketing campaigns', '10,000 AI credits per month', '5 team members'],
                'features_ar' => ['ربط غير محدود للقنوات والمتاجر', 'صندوق وارد موحد', 'سير عمل وتسلسلات غير محدودة', 'مساعد ذكاء اصطناعي وقاعدة معرفة', 'إدارة الحملات التسويقية', '10,000 رصيد AI شهرياً', '5 أعضاء في الفريق'],
                'is_popular' => false,
                'is_active' => true,
                'sort_order' => 2,
            ],
            'Growth' => [
                'name_ar' => 'النمو',
                'description' => 'Advanced AI and automation for businesses ready to scale.',
                'description_ar' => 'لأعمالك التي تريد التوسع وزيادة المبيعات.',
                'price_monthly' => 199,
                'price_yearly' => 2388,
                'ai_replies_limit' => 30000,
                'ai_credits_monthly' => 30000,
                'channels_limit' => -1,
                'outbound_messages_limit' => -1,
                'max_team_members' => 10,
                'tools_limit' => -1,
                'blog_posts_limit' => -1,
                'features' => ['Everything in Starter', 'Advanced AI agents', 'Multi-source AI knowledge base', 'AI campaign content', 'Advanced automation', 'Lead qualification', 'Advanced analytics and reports', '30,000 AI credits per month', '10 team members'],
                'features_ar' => ['كل مميزات Starter', 'مساعدو ذكاء اصطناعي متقدمون', 'قاعدة معرفة متعددة المصادر', 'إنشاء محتوى الحملات بالذكاء الاصطناعي', 'أتمتة متقدمة', 'تأهيل العملاء المحتملين', 'تحليلات وتقارير متقدمة', '30,000 رصيد AI شهرياً', '10 أعضاء في الفريق'],
                'is_popular' => true,
                'is_active' => true,
                'sort_order' => 3,
            ],
            'Business' => [
                'name_ar' => 'الأعمال',
                'description' => 'A complete workspace for larger companies and teams.',
                'description_ar' => 'حل متكامل للشركات والفرق الكبيرة.',
                'price_monthly' => 399,
                'price_yearly' => 4788,
                'ai_replies_limit' => 80000,
                'ai_credits_monthly' => 80000,
                'channels_limit' => -1,
                'outbound_messages_limit' => -1,
                'max_team_members' => -1,
                'tools_limit' => -1,
                'blog_posts_limit' => -1,
                'features' => ['Everything in Growth', 'Multiple AI agents', 'Advanced multi-scenario automation', 'Advanced campaign management', 'Human handoff', 'API and webhooks', 'Advanced analytics and reports', '80,000 AI credits per month', 'Unlimited team members'],
                'features_ar' => ['كل مميزات Growth', 'عدة مساعدين للذكاء الاصطناعي', 'أتمتة متقدمة متعددة السيناريوهات', 'إدارة متقدمة للحملات', 'تسليم المحادثات للموظفين', 'API وWebhooks', 'تقارير وتحليلات متقدمة', '80,000 رصيد AI شهرياً', 'أعضاء فريق غير محدودين'],
                'is_popular' => false,
                'is_active' => true,
                'sort_order' => 4,
            ],
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::definitions() as $name => $attributes) {
            Package::updateOrCreate(['name' => $name], $attributes + ['name' => $name]);
        }
    }
}
