<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\User;
use App\Form\FilePickerType;
use App\Service\StagedUpload;
use App\Service\StagedUploadStore;
use App\Service\UploadPolicy;
use App\Validator\AllowedUpload;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Validation;

/**
 * What the one upload field of this application does with what a browser submits.
 *
 * The interesting half is not the happy path: it is that a **token is never trusted**. The picker is
 * a convenience, the signature and the owner check are the control, and a form that accepted a token
 * it could not resolve would let one account claim another's object by copying a string out of a
 * page. So the refusal is what is pinned here, alongside the two shapes (single and multiple) and
 * the constraint the field builds for itself.
 *
 * Nothing here touches S3: App\Service\StagedUploadStore is stubbed, because what is under test is
 * the form, and the store has its own test over a real filesystem.
 */
// The opt-out is for a mock this test never made: TypeTestCase builds an EventDispatcher mock of its
// own to assemble the form factory, and PHPUnit 13 notices that nothing expects anything of it.
// phpunit.dist.xml has failOnNotice="true", so without this the harness's own scaffolding fails the
// suite.
#[AllowMockObjectsWithoutExpectations]
class FilePickerTypeTest extends TypeTestCase
{
    private StagedUploadStore&\PHPUnit\Framework\MockObject\Stub $store;

    protected function setUp(): void
    {
        $this->store = $this->createStub(StagedUploadStore::class);
        $this->store->method('resolve')->willReturnCallback(
            static fn (string $token, int $ownerId): ?StagedUpload => 'good-token' === $token && 12 === $ownerId
                ? new StagedUpload($token, 'staged/12/abc.pdf', 'cours.pdf', 'application/pdf', 2048)
                : null,
        );

        parent::setUp();
    }

    public function testASingleFieldResolvesItsTokenIntoAStagedUpload(): void
    {
        $form = $this->factory->create(FilePickerType::class);
        $form->submit(json_encode(['good-token']));

        self::assertTrue($form->isSynchronized());
        $data = $form->getData();
        self::assertInstanceOf(StagedUpload::class, $data);
        self::assertSame('cours.pdf', $data->originalName);
        self::assertSame(2048, $data->size);
    }

    public function testAMultipleFieldAnswersAList(): void
    {
        $form = $this->factory->create(FilePickerType::class, null, ['multiple' => true]);
        $form->submit(json_encode(['good-token', 'good-token']));

        self::assertTrue($form->isSynchronized());
        self::assertCount(2, $form->getData());
    }

    public function testAnEmptySubmissionIsEmptyRatherThanAnError(): void
    {
        $single = $this->factory->create(FilePickerType::class);
        $single->submit('');
        self::assertTrue($single->isSynchronized());
        self::assertNull($single->getData());

        $multiple = $this->factory->create(FilePickerType::class, null, ['multiple' => true]);
        $multiple->submit('');
        self::assertTrue($multiple->isSynchronized());
        self::assertSame([], $multiple->getData());
    }

    public function testATokenThatDoesNotResolveIsRefusedRatherThanAccepted(): void
    {
        $form = $this->factory->create(FilePickerType::class);
        $form->submit(json_encode(['forged-token']));

        // Not synchronized: the transformer refused, so the field is invalid and its data is null.
        // A form that accepted this would be handing one account another's object.
        self::assertFalse($form->isSynchronized());
        self::assertNull($form->getData());
    }

    public function testSomethingThatIsNotAListOfTokensIsRefused(): void
    {
        $form = $this->factory->create(FilePickerType::class);
        $form->submit('not json at all');

        self::assertFalse($form->isSynchronized());
    }

    public function testTheFieldBuildsItsOwnConstraintFromItsPolicy(): void
    {
        $single = $this->constraintsOf(['policy' => UploadPolicy::pdf()]);

        self::assertCount(1, $single);
        self::assertInstanceOf(AllowedUpload::class, $single[0]);
        self::assertSame(['pdf'], $single[0]->policy->extensions());

        // The half that used to be forgotten at every call site: a multiple field validates each
        // item, and a constraint aimed at the array itself answers "should be of type string".
        self::assertInstanceOf(All::class, $this->constraintsOf(['policy' => UploadPolicy::pdf(), 'multiple' => true])[0]);
    }

    public function testMaxSizeNarrowsThePolicyRatherThanSittingBesideIt(): void
    {
        $constraints = $this->constraintsOf(['policy' => UploadPolicy::images(), 'max_size' => '2M']);

        self::assertInstanceOf(AllowedUpload::class, $constraints[0]);
        self::assertSame(2 * 1024 * 1024, $constraints[0]->policy->maxSizeInBytes());
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<\Symfony\Component\Validator\Constraint>
     */
    private function constraintsOf(array $options): array
    {
        $constraints = $this->factory->create(FilePickerType::class, null, $options)->getConfig()->getOption('constraints');
        self::assertIsArray($constraints);

        return array_values($constraints);
    }

    protected function getExtensions(): array
    {
        $user = new User('picker.tester');
        // The id a token is checked against. Set through reflection because it is generated by the
        // database everywhere else, and this test has no database.
        $id = new \ReflectionProperty(User::class, 'id');
        $id->setValue($user, 12);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return [
            new PreloadedExtension([new FilePickerType($this->store, $security)], []),
            // The `constraints` option is the validator extension's, and this type normalises it -
            // building its own AllowedUpload from the policy. Without the extension the option does
            // not exist and every case here dies on resolution, which is a property of the bare test
            // harness rather than of the type: the application always has it.
            new ValidatorExtension(Validation::createValidator()),
        ];
    }
}
