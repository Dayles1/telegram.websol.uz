<?php

namespace Tests\Feature;

use App\Telegram\Madeline\SettingsFactory;
use App\Telegram\SessionStore;
use Tests\TestCase;

class MadelineSettingsTest extends TestCase
{
    public function test_a_session_speaks_the_language_of_its_phones_country(): void
    {
        $this->assertSame('uz', SettingsFactory::languageOf('+998 90 123 45 67'));
        $this->assertSame('ru', SettingsFactory::languageOf('79161234567'));
        $this->assertSame('ru', SettingsFactory::languageOf('+992 93 123 4567'));
        $this->assertSame('en', SettingsFactory::languageOf('+1 212 555 0100'));
        $this->assertSame('en', SettingsFactory::languageOf(null));

        $app = (new SettingsFactory)->make('+998901234567')->getAppInfo();
        $this->assertSame('uz', $app->getLangCode());
        $this->assertSame('uz', $app->getSystemLangCode());
        $this->assertSame('en', (new SettingsFactory)->make()->getAppInfo()->getLangCode(), 'never the process LANG');
    }

    public function test_the_phone_of_a_session_comes_from_its_file_name(): void
    {
        $this->assertSame('+998901234567', SessionStore::phoneOf(app(SessionStore::class)->pathFor('+998 90 123 45 67', 5)));
        $this->assertSame('+998901234567', SessionStore::phoneOf('/var/www/x/storage/app/sessions/+998901234567_add_user_3.madeline'));
        $this->assertNull(SessionStore::phoneOf('/tmp/whatever.madeline'));
    }
}
