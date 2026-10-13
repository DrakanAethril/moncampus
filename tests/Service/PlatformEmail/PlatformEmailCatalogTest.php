<?php

declare(strict_types=1);

namespace App\Tests\Service\PlatformEmail;

use App\Service\PlatformEmail\PlatformEmail;
use App\Service\PlatformEmail\PlatformEmailCatalog;
use App\Service\PlatformEmail\PlatformEmailPreviewer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * UFA > Configuration > Emails promises « the mails the platform sends ». Two things would make
 * that false without breaking anything: a mail template nobody listed, and a placeholder the
 * screen explains but the mail does not carry (or the reverse).
 */
class PlatformEmailCatalogTest extends KernelTestCase
{
    public function testEveryMailTemplateIsListed(): void
    {
        $listed = array_map(static fn (PlatformEmail $email): string => $email->template, (new PlatformEmailCatalog())->all());

        $templates = [];
        foreach ((new Finder())->files()->in(\dirname(__DIR__, 3).'/templates/emails')->depth(0)->name('*.html.twig')->notName('_*') as $file) {
            $templates[] = 'emails/'.$file->getFilename();
        }
        sort($templates);

        self::assertSame($templates, array_values(array_unique($this->sorted($listed))));
    }

    public function testKeysAreUnique(): void
    {
        $keys = array_map(static fn (PlatformEmail $email): string => $email->key, (new PlatformEmailCatalog())->all());

        self::assertSame($keys, array_values(array_unique($keys)));
    }

    #[DataProvider('emails')]
    public function testPreviewCarriesExactlyTheVariablesItExplains(PlatformEmail $email): void
    {
        $preview = self::getContainer()->get(PlatformEmailPreviewer::class)->preview($email);

        self::assertNotSame('', $preview->subject);
        self::assertStringNotContainsString('&', $preview->subject, 'The subject is plain text, not escaped HTML.');
        self::assertStringNotContainsString('cid:', $preview->html, 'A browser cannot resolve an attached picture.');

        // The stylesheet-free body: what the reader sees, subject included.
        preg_match_all('/\$[A-Z][A-Z_]*/', $preview->subject.' '.strip_tags($preview->html), $matches);

        self::assertSame($this->sorted(array_keys($email->variables)), $this->sorted(array_values(array_unique($matches[0]))));
    }

    #[DataProvider('emails')]
    public function testEveryLabelIsTranslated(PlatformEmail $email): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);

        foreach ([$email->titleKey, $email->whenKey, $email->group->labelKey(), ...array_values($email->variables)] as $key) {
            self::assertNotSame($key, $translator->trans($key, locale: 'fr'), $key);
        }
    }

    /** @return iterable<string, array{PlatformEmail}> */
    public static function emails(): iterable
    {
        foreach ((new PlatformEmailCatalog())->all() as $email) {
            yield $email->key => [$email];
        }
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
