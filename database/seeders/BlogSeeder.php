<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class BlogSeeder extends Seeder
{
    public function run(): void
    {
        $posts = [
            [
                [
                    'title' => 'كيف تضاعف مبيعاتك باستخدام الردود التلقائية بالذكاء الاصطناعي',
                    'title_en' => 'How to Double Your Sales with AI-Powered Auto-Replies',
                    'slug' => 'double-sales-ai-auto-replies',
                    'excerpt' => 'اكتشف كيف يمكن للردود التلقائية بالذكاء الاصطناعي أن تحول المزيد من الرسائل إلى مبيعات حقيقية وتزيد من معدل التحويل بنسبة 35%.',
                    'excerpt_en' => 'Discover how AI-powered auto-replies can turn more messages into real sales and increase your conversion rate by 35%.',
                    'body' => '<h2>لماذا الردود التلقائية؟</h2>
<p>في عالم التجارة الإلكترونية المتسارع، السرعة هي المفتاح. العملاء يتوقعون ردوداً فورية، وكل دقيقة تأخير يمكن أن تكلفك عميلاً محتمماً.</p>

<h2>القوة الحقيقية للذكاء الاصطناعي</h2>
<p>الذكاء الاصطناعي لا يرد فقط على الرسائل، بل يفهم السياق، ويحدد نية العميل، ويقدم حلولاً مناسباً لكل حالة.</p>

<h3>1. الاستجابة الفورية 24/7</h3>
<p>بينما أنت نائم، عملاؤك يرسلون استفسارات. مع الردود التلقائية، لا تفوت أي فرصة للبيع.</p>

<h3>2. تأهيل العملاء</h3>
<p>الذكاء الاصطناعي يصنف العملاء بناءً على استفساراتهم واهتمامهم، مما يسهل عليك التركيز على العملاء المحتملين.</p>

<h3>3. بناء الثقة</h3>
<p>الردود المتسقة والمهنية تبني الثقة مع عملائك وتجعلهم أكثر استعداداً للشراء.</p>

<h2>النتائج الحقيقية</h2>
<ul>
<li>زيادة معدل التحويل بنسبة 35%</li>
<li>تقليل وقت الرد من ساعات إلى ثوانٍ</li>
<li>تحسين رضا العملاء بنسبة 94%</li>
<li>تغطية 100% من الرسائل الواردة</li>
</ul>

<p>الذكاء الاصطناعي ليس مجرد أداة للرد، بل هو شريك استراتيجي في نمو عملك.</p>',
                    'body_en' => '<h2>Why Auto-Replies?</h2>
<p>In the fast-paced world of e-commerce, speed is key. Customers expect instant responses, and every minute of delay can cost you a potential sale.</p>

<h2>The True Power of AI</h2>
<p>AI doesn\'t just reply to messages—it understands context, identifies customer intent, and provides appropriate solutions for each case.</p>

<h3>1. Instant 24/7 Response</h3>
<p>While you sleep, your customers are sending inquiries. With auto-replies, you never miss a sales opportunity.</p>

<h3>2. Lead Qualification</h3>
<p>AI categorizes customers based on their inquiries and concerns, helping you focus on the most promising leads.</p>

<h3>3. Building Trust</h3>
<p>Consistent and professional responses build trust with your customers and make them more likely to purchase.</p>

<h2>Real Results</h2>
<ul>
<li>35% increase in conversion rate</li>
<li>Response time reduced from hours to seconds</li>
<li>94% customer satisfaction improvement</li>
<li>100% message coverage</li>
</ul>

<p>AI auto-replies are not just a response tool—they\'re a strategic partner in growing your business.</p>',
                    'category' => 'أتمتة المبيعات',
                    'author' => 'فريق ناز',
                    'status' => 'published',
                    'published_at' => Carbon::now()->subDays(7),
                    'featured_image_url' => 'https://images.unsplash.com/photo-1551434685726-6f7602e7a1d4?w=800',
                    'meta_description' => 'دليل شامل حول كيفية استخدام الردود التلقائية بالذكاء الاصطناعي لزيادة المبيع وتحسين خدمة العملاء.',
                    'tags' => ['أتمتة المبيعات', 'ذكاء اصطناعي', 'زيادة المبيعات'],
                    'created_at' => Carbon::now()->subDays(7),
                    'updated_at' => Carbon::now()->subDays(7),
                ],
                [
                    'title' => 'دليل المبتدئين في التجارة الإلكترونية: من الفكرة إلى أول ألف عميل',
                    'title_en' => 'E-commerce Beginner Guide: From Idea to First 1000 Customers',
                    'slug' => 'ecommerce-beginner-guide',
                    'excerpt' => 'الخطوات العملية والاستراتيجيات التي تحتاجها لبدء متجر إلكتروني ناجح وجذب أول 1000 عميل.',
                    'excerpt_en' => 'The actionable steps and strategies you need to start a successful e-commerce business and attract your first 1000 customers.',
                    'body' => '<h2>الخطوة 1: اختيار المنتج المناسب</h2>
<p>النجاح في التجارة الإلكترونية يبدأ باختيار المنتج الصحيح. ابحث عن منتج يحل مشكلة حقيقية وله طلب في السوق.</p>

<h2>الخطوة 2: بناء متجر جذاب بصرياً</h2>
<p>استخدم منصات مثل Shopify أو WooCommerce لبناء متجر احترافي. استثمر في التصميم وتجربة المستخدم.</p>

<h2>الخطوة 3: التسويق عبر القنوات الاجتماعية</h2>
<p>أنشئ حضوراً قوياً على إنستغرام وفيسبوك. استخدم الصور الجذابة والتفاعل المستمر مع المتابعين.</p>

<h2>الخطوة 4: استخدام أدوات التسويق</h2>
<p>استفد من الأدوات المجانية مثل ناز لإدارة الردود وتأهيل العملاء تلقائياً.</p>

<h2>الخطوة 5: تحليل البيانات وتحسين الأداء</h2>
<p>راقب مقاييس الأداء مثل معدل التحويل، تكلفة الاستحوا، وقيمة الطلب العمر.</p>

<p>ابدأ صغيراً، وسحق النتائج، ثم قم بالتوسع تدريجياً.</p>',
                    'body_en' => '<h2>Step 1: Choose the Right Product</h2>
<p>E-commerce success starts with choosing the right product. Look for a product that solves a real problem and has market demand.</p>

<h2>Step 2: Build an Attractive Store</h2>
<p>Use platforms like Shopify or WooCommerce to build a professional store. Invest in design and user experience.</p>

<h2>Step 3: Social Media Marketing</h2>
<p>Build a strong presence on Instagram and Facebook. Use engaging photos and consistent interaction with followers.</p>

<h2>Step 4: Use Coordination Tools</h2>
<p>Leverage free tools like Naz to manage responses and qualify leads automatically.</p>

<h2>Step 5: Analyze Data and Optimize</h2>
<p>Track performance metrics like conversion rate, acquisition cost, and customer lifetime value.</p>

<p>Start small, achieve results, then scale gradually.</p>',
                    'category' => 'ريادة الأعمال',
                    'author' => 'فريق ناز',
                    'status' => 'published',
                    'published_at' => Carbon::now()->subDays(14),
                    'featured_image_url' => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=800',
                    'meta_description' => 'دليل شامل للمبتدئين في التجارة الإلكترونية: كيف تبدأ متجر ناجح وجذب أول 1000 عميل.',
                    'tags' => ['ريادة الأعمال', 'التجارة الإلكترونية', 'دليل المبتدئين'],
                    'created_at' => Carbon::now()->subDays(14),
                    'updated_at' => Carbon::now()->subDays(14),
                ],
                [
                    'title' => 'أهم 5 أدوات مجانية لزيادة مبيعاتك في 2024',
                    'title_en' => 'Top 5 Free Tools to Boost Your Sales in 2024',
                    'slug' => 'top-5-free-tools-boost-sales',
                    'excerpt' => 'استكشف أفضل الأدوات المجانية التي تساعدك على زيادة المبيعات، تحسين خدمة العملاء، وتنمية عملك دون تكاليف إضافية.',
                    'excerpt_en' => 'Discover the best free tools that help you increase sales, improve customer service, and grow your business without additional costs.',
                    'body' => '<h2>1. مولد نصوص البيع</h2>
<p>يحول استفسارات العملاء إلى نصوص بيع مقنعة باستخدام الذكاء الاصطناعي.</p>

<h2>2. محلل الشكاوى</h2>
<p>يراجع التقييمات السلبية ويقترح استجابات ذكية لحل المشاكل وحماية سمعتك.</p>

<h2>3. مولد أفكار الحملات</h2>
<p>يساعدك على إنشاء حملات إعلانية فعالة باستخدام الذكاء الاصطناعي.</p>

<h2>4. حاسبة التسعير الذكية</h2>
<p>تحلل بيانات السوق لتحديد الأسعار المثلى لمنتجاتك.</p>

<h2>5. محول النبرة</h2>
<p>عدل نبرة رسائلك لتناسب مختلف المواقف والعملاء.</p>

<p>هذه الأدوات متاحة مجاناً ويمكنها أن تحدث تحسن كبيراً في عملياتك.</p>',
                    'body_en' => '<h2>1. Sales Script Generator</h2>
<p>Transforms customer inquiries into sales-oriented scripts using AI.</p>

<h2>2. Complaint Analyzer</h2>
<p>Reviews negative reviews and suggests intelligent responses to solve problems and protect your reputation.</p>

<h2>3. Campaign Ideator</h2>
<p>Helps you create effective ad campaigns using AI.</p>

<h2>4. Smart Pricing Calculator</h2>
<p>Analyzes market data to determine optimal pricing for your products.</p>

<h2>5. Tone Transformer</h2>
<p>Adjusts the tone of your messages to suit different situations and customers.</p>

<p>These tools are available for free and can significantly improve your operations.</p>',
                    'category' => 'التجارة الإلكترونية',
                    'author' => 'فريق ناز',
                    'status' => 'published',
                    'published_at' => Carbon::now()->subDays(3),
                    'featured_image_url' => 'https://images.unsplash.com/photo-1551288049-feb661d7264a?w=800',
                    'meta_description' => 'استكشف أفضل الأدوات المجانية لزيادة المبيعات وتحسين خدمة العملاء في 2024.',
                    'tags' => ['التجارة الإلكترونية', 'أدوات مجانية', 'زيادة المبيعات'],
                    'created_at' => Carbon::now()->subDays(3),
                    'updated_at' => Carbon::now()->subDays(3),
                ],
                [
                    'title' => 'كيف تحمي سمعتك على Google باستخدام الردود الذكية',
                    'title_en' => 'How to Protect Your Google Reputation with Smart Replies',
                    'slug' => 'protect-google-reputation-smart-replies',
                    'excerpt' => 'تعلم كيف يمكن للردود الذكية أن تكتشف العملاء غير الراضين وتتصل معهم قبل أن يتركوا تقييماً سلبياً.',
                    'excerpt_en' => 'Learn how smart replies can detect dissatisfied customers and reach out to them before they leave negative reviews.',
                    'body' => '<h2>مشكلة التقييمات السلبية</h2>
<p>تقييم واحد سلبي يمكن أن يضر بسمعتك لسنوات. من المهم معالجة المشكلات بسرعة.</p>

<h2>كيف يعمل نظام الاكتشاف</h2>
<p>الذكاء الاصطناعي يرصد الإشارات السلبية المحتملة بناءً على الكلمات والعبارات المشتركة.</p>

<h2>الاستجابة الذكية</h2>
<p>بدلاً من رد عام، يقدم الذكاء الاصطناعي استجابة شخصية تعالج المخاوف مباشرة.</p>

<h2>التتبع والتحسين</h2>
<p>يراقب استجاباتك ويعلم من التفاعل لتحسين الاستراتيجية مع الوقت.</p>

<p>الحماية من سمعتك الاستثمار الذكي يستحق الوقت والجهد.</p>',
                    'body_en' => '<h2>The Problem with Negative Reviews</h2>
<p>A single negative review can damage your reputation for years. It\'s crucial to address issues quickly.</p>

<h2>How Detection Works</h2>
<p>AI identifies potentially negative feedback based on keywords and sentiment patterns.</p>

<h2>Smart Response</h2>
<p>Instead of a generic reply, AI provides a personalized response that addresses concerns immediately.</p>

<h2>Follow-up and Improvement</h2>
<p>Track your responses and learn from interactions to improve your strategy over time.</p>

<p>Protecting your reputation with smart responses is a smart investment of time and effort.</p>',
                    'category' => 'التجارة الإلكترونية',
                    'author' => 'فريق ناز',
                    'status' => 'published',
                    'published_at' => Carbon::now()->subDays(10),
                    'featured_image_url' => 'https://images.unsplash.com/photo-1516321483858-d7f99504770c?w=800',
                    'meta_description' => 'استراتيجيات فعالة لحماية سمعتك على Google باستخدام الردود الذكية.',
                    'tags' => ['سمعة', 'تقييمات Google', 'حماية السمعة'],
                    'created_at' => Carbon::now()->subDays(10),
                    'updated_at' => Carbon::now()->subDays(10),
                ],
                [
                    'title' => 'استراتيجيات التسويق المبتدئين لمتاجر السوشال ميديا',
                    'title_en' => 'Social Media Marketing Strategies for Beginners',
                    'slug' => 'social-media-marketing-strategies',
                    'excerpt' => 'خطوات بسيطة وفعالة لبناء حضور قوي على منصات التواصل الاجتماعي وجذب المزيد من المتابعين.',
                    'excerpt_en' => 'Simple and effective steps to build a strong presence on social media platforms and attract more followers.',
                    'body' => '<h2>الاستمرار قبل البدء</h2>
<p>حدد منصاتك المستهدة بناءً على نوع منتجك وجمهورك المستهدف.</p>

<h2>إنشاء محتوى جذاب</h2>
<p>ركز على المحتوى الذي يقدم قيمة للعملاء وليس مجرد الترويج.</p>

<h2>التفاعل مع المتابعين</h2>
<p>الرد على التعليقات والرسائل يساعد في بناء مجتمع حول علامتك.</p>

<h2>الاستخدام المدروس للإعلانات</h2>
<p>استهدف فئات محددة من المتابعين للوصول للجمهور المناسب.</p>

<p>التسويق في السوشال ميديا يتطلب الصبر والاستمرار، لكن النتائج تستحق العناء.</p>',
                    'body_en' => '<h2>Pre-Launch Planning</h2>
<p>Identify your target platforms based on your product type and target audience.</p>

<h2>Creating Engaging Content</h2>
<p>Focus on content that provides value to customers rather than just promotion.</p>

<h2>Engaging with Followers</h2>
<p>Responding to comments and messages helps build a community around your brand.</p>

<h2>Using Paid Ads</h2>
<p>Target specific follower segments to reach the right audience.</p>

<p>Social media marketing requires patience and consistency, but the results are worth the effort.</p>',
                    'category' => 'ريادة الأعمال',
                    'author' => 'فريق ناز',
                    'status' => 'published',
                    'published_at' => Carbon::now()->subDays(5),
                    'featured_image_url' => 'https://images.unsplash.com/photo-1611162617474-5b21e8e6fb387?w=800',
                    'meta_description' => 'استراتيجيات التسويق في السوشال ميديا للمبتدئين.',
                    'tags' => ['تسويق سوشال ميديا', 'ريادة الأعمال', 'نمو المتابعين'],
                    'created_at' => Carbon::now()->subDays(5),
                    'updated_at' => Carbon::now()->subDays(5),
                ],
            ],
        ];

        foreach ($posts as $postData) {
            $existingPost = \App\Models\Post::where('slug', $postData['slug'])->first();
            
            if (!$existingPost) {
                \App\Models\Post::create($postData);
                echo "Created post: {$postData['title']}\n";
            } else {
                echo "Post already exists: {$postData['title']}\n";
            }
        }

        $this->command->info('Blog seeding completed successfully.');
    }
}