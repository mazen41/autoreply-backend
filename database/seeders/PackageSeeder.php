<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Package;

class PackageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Package::create([
            'name' => 'Free',
            'name_ar' => 'مجاني',
            'description' => 'Perfect for getting started with AI-powered automated replies.',
            'description_ar' => 'مثالي للبدء مع الردود الآلية المدعومة بالذكاء الاصطناعي.',
            'price_monthly' => 0.00,
            'price_yearly' => 0.00,
            'ai_replies_limit' => 50,
            'channels_limit' => 1,
            'tools_limit' => -1,
            'blog_posts_limit' => 0,
            'features' => [
                '50 AI replies per month',
                '1 connected channel',
                'Access to all free tools',
                'Email support'
            ],
            'features_ar' => [
                '50 رد آلي شهرياً',
                'قناة واحدة متصلة',
                'الوصول لجميع الأدوات المجانية',
                'دعم عبر البريد الإلكتروني'
            ],
            'is_popular' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Package::create([
            'name' => 'Starter',
            'name_ar' => 'المبتدئ',
            'description' => 'Great for growing businesses with multiple channels.',
            'description_ar' => 'رائع للشركات النامية مع قنوات متعددة.',
            'price_monthly' => 99.00,
            'price_yearly' => 990.00,
            'ai_replies_limit' => 1000,
            'channels_limit' => 3,
            'tools_limit' => -1,
            'blog_posts_limit' => 10,
            'features' => [
                '1,000 AI replies per month',
                '3 connected channels',
                'Facebook, Instagram & Gmail',
                'Automated blog (10 posts/month)',
                'Priority support',
                'Analytics dashboard'
            ],
            'features_ar' => [
                '1,000 رد آلي شهرياً',
                '3 قنوات متصلة',
                'فيسبوك وانستغرام وجيميل',
                'مدونة مؤتمتة (10 مقالات/شهر)',
                'دعم أولوية',
                'لوحة تحليلات'
            ],
            'is_popular' => true,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        Package::create([
            'name' => 'Pro',
            'name_ar' => 'الاحترافي',
            'description' => 'For businesses that need unlimited AI-powered automation.',
            'description_ar' => 'للشركات التي تحتاج إلى أتمتة غير محدودة بالذكاء الاصطناعي.',
            'price_monthly' => 299.00,
            'price_yearly' => 2990.00,
            'ai_replies_limit' => -1,
            'channels_limit' => -1,
            'tools_limit' => -1,
            'blog_posts_limit' => -1,
            'features' => [
                'Unlimited AI replies',
                'Unlimited channels',
                'WhatsApp integration',
                'Unlimited blog automation',
                'Dedicated account manager',
                'Custom AI personality',
                'White-label option'
            ],
            'features_ar' => [
                'ردود آلية غير محدودة',
                'قنوات غير محدودة',
                'تكامل واتساب',
                'أتمتة مدونة غير محدودة',
                'مدير حساب مخصص',
                'شخصية ذكاء اصطناعي مخصصة',
                'خيار العلامة البيضاء'
            ],
            'is_popular' => false,
            'is_active' => true,
            'sort_order' => 3,
        ]);
    }
}
