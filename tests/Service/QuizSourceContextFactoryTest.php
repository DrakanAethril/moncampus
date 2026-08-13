<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\SeancePhaseTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Enum\QuizSourceScope;
use App\Service\QuizSourceContextFactory;
use PHPUnit\Framework\TestCase;

/**
 * The part that reads the library and hands App\Service\QuizSourceContext its four primitives.
 *
 * What it decides is what "the course" means for a prompt, and the answers are all subtractions:
 *
 * - **The scope's own objectives**, not every text field. A prompt carrying prerequisites,
 *   cross-curricular links and the problem situation asks a model to write questions about the
 *   syllabus rather than about the lesson.
 * - **The phases' content**, named and timed. A phase without content contributes its name and
 *   nothing else, because a heading with nothing under it costs characters and teaches nothing.
 * - **Plain text only.** The one HTML field of the three entities (cahier de texte) is not part of
 *   the context at all, and any HTML that reached a plain field anyway is flattened - the counter on
 *   screen must count what the model reads, not the tags around it.
 */
class QuizSourceContextFactoryTest extends TestCase
{
    private QuizSourceContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new QuizSourceContextFactory();
    }

    public function testASequenceCarriesItsObjectivesAndEverySeancesPhases(): void
    {
        $context = $this->factory->forSequence($this->sequence());

        self::assertSame(QuizSourceScope::Sequence, $context->scope);
        self::assertSame('Automatisation avec Ansible', $context->title);
        self::assertStringContainsString('principe agentless', $context->objectives());
        // Both séances, each naming itself, so a question can be about the right moment of the course.
        self::assertStringContainsString('Prendre la main sur un parc', $context->phases());
        self::assertStringContainsString('Playbooks', $context->phases());
        self::assertStringContainsString('Situation déclenchante', $context->phases());
        self::assertStringContainsString('Écrire un playbook idempotent', $context->phases());
    }

    public function testASeanceCarriesItsOwnObjectivesAndItsOwnPhasesOnly(): void
    {
        $sequence = $this->sequence();
        $first = $sequence->getSeanceTemplates()->first();
        self::assertInstanceOf(SeanceTemplate::class, $first);

        $context = $this->factory->forSeance($first);

        self::assertSame(QuizSourceScope::Seance, $context->scope);
        self::assertSame('Prendre la main sur un parc', $context->title);
        self::assertStringContainsString('inventaire statique', $context->objectives());
        self::assertStringNotContainsString('principe agentless', $context->objectives(), 'the séquence\'s objectives are not the séance\'s');
        self::assertStringContainsString('Situation déclenchante', $context->phases());
        self::assertStringNotContainsString('Écrire un playbook idempotent', $context->phases(), 'another séance\'s phases are not this one\'s');
    }

    /** A phase carries its duration: "20 min" is what makes a question about it proportionate. */
    public function testAPhaseIsNamedAndTimed(): void
    {
        $phases = $this->factory->forSequence($this->sequence())->phases();

        self::assertStringContainsString('Accueil et problématisation', $phases);
        self::assertStringContainsString('20 min', $phases);
    }

    public function testAPhaseWithoutContentContributesItsNameAndNoEmptyHeading(): void
    {
        $sequence = new SequenceTemplate(new User('prof'));
        $sequence->setTitre('Séquence');
        $seance = new SeanceTemplate($sequence);
        $seance->setTitre('Séance');
        $sequence->getSeanceTemplates()->add($seance);
        $phase = new SeancePhaseTemplate($seance);
        $phase->setNom('Phase sans contenu');
        $seance->getSeancePhaseTemplates()->add($phase);

        $phases = $this->factory->forSequence($sequence)->phases();

        self::assertStringContainsString('Phase sans contenu', $phases);
        self::assertStringNotContainsString(':  ', $phases, 'no dangling separator where the content would have been');
        self::assertSame(trim($phases), $phases);
    }

    /** Nothing written down anywhere: the screen must be able to say "there is no context to send". */
    public function testAnEmptyCourseOffersNothing(): void
    {
        $sequence = new SequenceTemplate(new User('prof'));
        $sequence->setTitre('Séquence vide');

        $context = $this->factory->forSequence($sequence);

        self::assertFalse($context->hasObjectives());
        self::assertFalse($context->hasPhases());
    }

    /**
     * HTML that reached a plain-text field anyway is flattened rather than sent as tags. The counter
     * on screen has to count what the model reads - and a `<p>` is characters the teacher is paying
     * for and the model has to skip.
     */
    public function testHtmlThatReachedAPlainFieldIsFlattenedRatherThanSent(): void
    {
        $sequence = new SequenceTemplate(new User('prof'));
        $sequence->setTitre('Séquence');
        $sequence->setObjectifs('<p>Objectif <strong>net</strong></p>');

        $objectives = $this->factory->forSequence($sequence)->objectives();

        self::assertStringNotContainsString('<p>', $objectives);
        self::assertStringNotContainsString('<strong>', $objectives);
        self::assertStringContainsString('Objectif net', $objectives);
    }

    private function sequence(): SequenceTemplate
    {
        $sequence = new SequenceTemplate(new User('prof-001'));
        $sequence->setTitre('Automatisation avec Ansible');
        $sequence->setObjectifs('Expliquer le principe agentless et le rôle du nœud de contrôle.');
        // Not part of the context on purpose - see the class docblock.
        $sequence->setPreRequis('SSH, apt, droits Unix');

        $first = new SeanceTemplate($sequence);
        $first->setTitre('Prendre la main sur un parc');
        $first->setOrdre(1);
        $first->setObjectifs('Écrire un inventaire statique avec groupes et variables.');
        $sequence->getSeanceTemplates()->add($first);

        $accueil = new SeancePhaseTemplate($first);
        $accueil->setOrdre(1);
        $accueil->setNom('Accueil et problématisation');
        $accueil->setDuree('20');
        $accueil->setContenu('Situation déclenchante projetée : douze agences à déployer.');
        $first->getSeancePhaseTemplates()->add($accueil);

        $second = new SeanceTemplate($sequence);
        $second->setTitre('Playbooks : tâches et variables');
        $second->setOrdre(2);
        $sequence->getSeanceTemplates()->add($second);

        $atelier = new SeancePhaseTemplate($second);
        $atelier->setOrdre(1);
        $atelier->setNom('Atelier guidé');
        $atelier->setDuree('75');
        $atelier->setContenu('Écrire un playbook idempotent, le rejouer deux fois.');
        $second->getSeancePhaseTemplates()->add($atelier);

        return $sequence;
    }
}
