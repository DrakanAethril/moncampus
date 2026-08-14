<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\QueryValue;
use App\Service\DataModel\DoctrineModelReader;
use App\Service\DataModel\DomainMap;
use App\Service\DataModel\NotationGenerator;
use App\Service\DataModel\SqlDdlProvider;
use App\Service\TechnicalProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Description technique" - what this application is made of, written for the students who study
 * in the school it runs for.
 *
 * The school prepares the BTS SIO, so the page is laid out the way that syllabus is: a common trunk,
 * then what concerns the SLAM option (development) and what concerns SISR (infrastructure and
 * networks). It describes the application as it is - no roadmap, no "what could be improved": a
 * student reading it must be able to open the source and find exactly what is claimed.
 *
 * Every figure on the page is measured, not typed (App\Service\TechnicalProfile). That is
 * deliberate and pedagogical in itself: the volumetry of a codebase is a fact about a commit, not a
 * sentence in a template.
 *
 * Open to every authenticated account, like "À propos" and "Changelog", and linked from the profile
 * menu just above "À propos".
 */
#[IsGranted('ROLE_USER')]
class TechnicalController extends AbstractController
{
    /**
     * @param array{source_url: string} $about the app.about parameter - only its source_url is read
     *                                         here, and it is the same one "À propos" offers, so the
     *                                         two links can never point at different repositories
     */
    #[Route(path: '/technical', name: 'app_technical', methods: ['GET'])]
    public function index(
        TechnicalProfile $profile,
        #[Autowire(param: 'app.about')] array $about,
    ): Response {
        return $this->render('technical/index.html.twig', [
            'figures' => $profile->figures(),
            'repositoryUrl' => $about['source_url'],
        ]);
    }

    /**
     * The data model under its four classic notations - MCD, MLD, MPD, UML - generated from
     * Doctrine's metadata at display time, one functional domain at a time. Same rule as the rest
     * of the page: nothing drawn by hand, so the diagrams cannot drift from the schema.
     */
    #[Route(path: '/technical/data-model', name: 'app_technical_data_model', methods: ['GET'])]
    public function dataModel(
        Request $request,
        DoctrineModelReader $reader,
        DomainMap $domainMap,
        NotationGenerator $generator,
    ): Response {
        $model = $reader->read();
        $domains = $domainMap->domains($model);
        $domain = QueryValue::string($request, 'domain');
        if (!isset($domains[$domain])) {
            $domain = (string) array_key_first($domains);
        }
        $names = $domains[$domain];

        return $this->render('technical/data_model.html.twig', [
            'domains' => array_keys($domains),
            'domain' => $domain,
            'entityCount' => count($names),
            'totalCount' => count($model->entities),
            'mcd' => $generator->mcd($model, $names),
            'mld' => $generator->mld($model, $names),
            'mpd' => $generator->mpd($model, $names),
            'uml' => $generator->uml($model, $names),
        ]);
    }

    /**
     * Full-model sources for the tools students use outside the browser: Mocodo (MCD), plain-text
     * relational schema (MLD), SQL DDL (MPD), PlantUML (UML class diagram).
     */
    #[Route(path: '/technical/data-model/export/{format}', name: 'app_technical_data_model_export', requirements: ['format' => 'mocodo|mld|sql|plantuml'], methods: ['GET'])]
    public function dataModelExport(
        string $format,
        DoctrineModelReader $reader,
        NotationGenerator $generator,
        SqlDdlProvider $ddl,
    ): Response {
        $model = $reader->read();
        $names = array_keys($model->entities);
        [$content, $filename] = match ($format) {
            'mocodo' => [$generator->mocodo($model, $names), 'moncampus-mcd.mcd'],
            'mld' => [$generator->mldText($model, $names), 'moncampus-mld.txt'],
            'sql' => [$ddl->ddl(), 'moncampus-mpd.sql'],
            'plantuml' => [$generator->plantUml($model, $names), 'moncampus-uml.puml'],
            default => throw $this->createNotFoundException(),
        };

        $response = new Response($content, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename));

        return $response;
    }
}
