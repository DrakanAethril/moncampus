<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\FixedAvatarFamily;
use Symfony\Component\Asset\Packages;

/**
 * The ménagerie: a hundred ready-made avatars - thirty monsters, forty animals, thirty pieces of
 * nature - drawn once and never personalised. They are the only avatars offered to a visitor who
 * joins a public live contest as a guest (twenty drawn among them, « Nouveaux avatars » draws twenty
 * others), and one of the choices of « Mon avatar » on the profile (`AvatarChoice::Fixed`).
 *
 * An avatar is stored as its identifier, never as a drawing: the picture is the file
 * `assets/images/menagerie/{id}.svg`, served by AssetMapper, so redrawing one changes it
 * everywhere at once. An identifier is therefore never renamed nor reused for another drawing; an
 * identifier that leaves the catalogue simply stops answering a picture (the initials show).
 */
final readonly class FixedAvatars
{
    /** @var array<string, array{0: string, 1: FixedAvatarFamily}> */
    private const array CATALOGUE = [
        'glouton' => ['Glouton', FixedAvatarFamily::Monster],
        'bloblo' => ['Bloblo', FixedAvatarFamily::Monster],
        'bigleux' => ['Bigleux', FixedAvatarFamily::Monster],
        'trioculus' => ['Trioculus', FixedAvatarFamily::Monster],
        'yeti' => ['Yéti Doudou', FixedAvatarFamily::Monster],
        'bouh' => ['Bouh', FixedAvatarFamily::Monster],
        'dragonnet' => ['Dragonnet', FixedAvatarFamily::Monster],
        'diablotin' => ['Diablotin', FixedAvatarFamily::Monster],
        'pompon' => ['Pompon', FixedAvatarFamily::Monster],
        'gelatine' => ['Gélatine', FixedAvatarFamily::Monster],
        'zorg' => ['Zorg', FixedAvatarFamily::Monster],
        'momie' => ['Momie Mimi', FixedAvatarFamily::Monster],
        'comte-bisou' => ['Comte Bisou', FixedAvatarFamily::Monster],
        'golemou' => ['Golemou', FixedAvatarFamily::Monster],
        'nessie' => ['Nessie', FixedAvatarFamily::Monster],
        'paillette' => ['Paillette', FixedAvatarFamily::Monster],
        'chaussette' => ['Chaussette', FixedAvatarFamily::Monster],
        'zigzag' => ['Zigzag', FixedAvatarFamily::Monster],
        'antennor' => ['Antennor', FixedAvatarFamily::Monster],
        'rex' => ['Rex', FixedAvatarFamily::Monster],
        'oreillon' => ['Oreillon', FixedAvatarFamily::Monster],
        'ossette' => ['Ossette', FixedAvatarFamily::Monster],
        'potiron' => ['Potiron', FixedAvatarFamily::Monster],
        'farfadet' => ['Farfadet', FixedAvatarFamily::Monster],
        'placardou' => ['Placardou', FixedAvatarFamily::Monster],
        'feuillu' => ['Feuillu', FixedAvatarFamily::Monster],
        'coquille' => ['Coquille', FixedAvatarFamily::Monster],
        'picoti' => ['Picoti', FixedAvatarFamily::Monster],
        'carton' => ['Carton', FixedAvatarFamily::Monster],
        'belzebulle' => ['Belzébulle', FixedAvatarFamily::Monster],
        'minou' => ['Minou', FixedAvatarFamily::Animal],
        'toutou' => ['Toutou', FixedAvatarFamily::Animal],
        'goupil' => ['Goupil', FixedAvatarFamily::Animal],
        'bambou' => ['Bambou', FixedAvatarFamily::Animal],
        'koko' => ['Koko', FixedAvatarFamily::Animal],
        'rainette' => ['Rainette', FixedAvatarFamily::Animal],
        'hulotte' => ['Hulotte', FixedAvatarFamily::Animal],
        'frac' => ['Frac', FixedAvatarFamily::Animal],
        'porcelet' => ['Porcelet', FixedAvatarFamily::Animal],
        'meuh' => ['Meuh-Meuh', FixedAvatarFamily::Animal],
        'carotte' => ['Carotte', FixedAvatarFamily::Animal],
        'nounours' => ['Nounours', FixedAvatarFamily::Animal],
        'criniere' => ['Crinière', FixedAvatarFamily::Animal],
        'rayure' => ['Rayure', FixedAvatarFamily::Animal],
        'masque' => ['Masqué', FixedAvatarFamily::Animal],
        'piquant' => ['Piquant', FixedAvatarFamily::Animal],
        'flemmard' => ['Flemmard', FixedAvatarFamily::Animal],
        'tentacule' => ['Tentacule', FixedAvatarFamily::Animal],
        'plouf' => ['Plouf', FixedAvatarFamily::Animal],
        'aileron' => ['Aileron', FixedAvatarFamily::Animal],
        'pince-mi' => ['Pince-Mi', FixedAvatarFamily::Animal],
        'gelule' => ['Gélule', FixedAvatarFamily::Animal],
        'axel' => ['Axel', FixedAvatarFamily::Animal],
        'piou' => ['Piou', FixedAvatarFamily::Animal],
        'coin-coin' => ['Coin-Coin', FixedAvatarFamily::Animal],
        'rosette' => ['Rosette', FixedAvatarFamily::Animal],
        'coco' => ['Coco', FixedAvatarFamily::Animal],
        'bajoue' => ['Bajoue', FixedAvatarFamily::Animal],
        'grignotte' => ['Grignotte', FixedAvatarFamily::Animal],
        'trompette' => ['Trompette', FixedAvatarFamily::Animal],
        'turbo' => ['Turbo', FixedAvatarFamily::Animal],
        'bzz' => ['Bzz', FixedAvatarFamily::Animal],
        'pois-pois' => ['Pois-Pois', FixedAvatarFamily::Animal],
        'carapace' => ['Carapace', FixedAvatarFamily::Animal],
        'croco' => ['Croco', FixedAvatarFamily::Animal],
        'frisette' => ['Frisette', FixedAvatarFamily::Animal],
        'capy' => ['Capy', FixedAvatarFamily::Animal],
        'lama' => ['Lama Zen', FixedAvatarFamily::Animal],
        'loutrinette' => ['Loutrinette', FixedAvatarFamily::Animal],
        'barrage' => ['Barrage', FixedAvatarFamily::Animal],
        'rayon' => ['Rayon', FixedAvatarFamily::Nature],
        'lunette' => ['Lunette', FixedAvatarFamily::Nature],
        'cumulus' => ['Cumulus', FixedAvatarFamily::Nature],
        'grisaille' => ['Grisaille', FixedAvatarFamily::Nature],
        'zap' => ['Zap', FixedAvatarFamily::Nature],
        'prisme' => ['Prisme', FixedAvatarFamily::Nature],
        'etincelle' => ['Étincelle', FixedAvatarFamily::Nature],
        'comete' => ['Comète', FixedAvatarFamily::Nature],
        'flocon' => ['Flocon', FixedAvatarFamily::Nature],
        'flammeche' => ['Flammèche', FixedAvatarFamily::Nature],
        'gouttelette' => ['Gouttelette', FixedAvatarFamily::Nature],
        'surf' => ['Surf', FixedAvatarFamily::Nature],
        'grondin' => ['Grondin', FixedAvatarFamily::Nature],
        'sommet' => ['Sommet', FixedAvatarFamily::Nature],
        'galet' => ['Galet', FixedAvatarFamily::Nature],
        'piquette' => ['Piquette', FixedAvatarFamily::Nature],
        'champi' => ['Champi', FixedAvatarFamily::Nature],
        'marguerite' => ['Marguerite', FixedAvatarFamily::Nature],
        'tournesol' => ['Tournesol', FixedAvatarFamily::Nature],
        'rouquine' => ['Rouquine', FixedAvatarFamily::Nature],
        'sapinou' => ['Sapinou', FixedAvatarFamily::Nature],
        'gland' => ['Gland', FixedAvatarFamily::Nature],
        'glacon' => ['Glaçon', FixedAvatarFamily::Nature],
        'tourbillon' => ['Tourbillon', FixedAvatarFamily::Nature],
        'saturnin' => ['Saturnin', FixedAvatarFamily::Nature],
        'coquillou' => ['Coquillou', FixedAvatarFamily::Nature],
        'fraisinette' => ['Fraisinette', FixedAvatarFamily::Nature],
        'pousse' => ['Pousse', FixedAvatarFamily::Nature],
        'banquise' => ['Banquise', FixedAvatarFamily::Nature],
        'planete-bleue' => ['Planète Bleue', FixedAvatarFamily::Nature],
    ];

    public function __construct(private Packages $packages)
    {
    }

    public static function exists(string $id): bool
    {
        return isset(self::CATALOGUE[$id]);
    }

    /** The picture of an avatar of the catalogue - null for an identifier it does not hold. */
    public function url(?string $id): ?string
    {
        return null !== $id && self::exists($id) ? $this->packages->getUrl('images/menagerie/'.$id.'.svg') : null;
    }

    /**
     * Every avatar, in the catalogue's order.
     *
     * @return list<array{id: string, name: string, family: FixedAvatarFamily, url: string}>
     */
    public function all(): array
    {
        $out = [];
        foreach (self::CATALOGUE as $id => [$name, $family]) {
            $out[] = ['id' => $id, 'name' => $name, 'family' => $family, 'url' => $this->packages->getUrl('images/menagerie/'.$id.'.svg')];
        }

        return $out;
    }

    /**
     * The same, one group per family in the catalogue's order - the profile's picker.
     *
     * @return list<array{family: FixedAvatarFamily, avatars: list<array{id: string, name: string, family: FixedAvatarFamily, url: string}>}>
     */
    public function byFamily(): array
    {
        $groups = [];
        foreach ($this->all() as $avatar) {
            $groups[$avatar['family']->value] ??= ['family' => $avatar['family'], 'avatars' => []];
            $groups[$avatar['family']->value]['avatars'][] = $avatar;
        }

        return array_values($groups);
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /** The display name of an avatar of the catalogue - null for an identifier it does not hold. */
    public static function name(string $id): ?string
    {
        return self::CATALOGUE[$id][0] ?? null;
    }
}
