<?php

namespace App\Entity;

use App\Repository\LibraryNiveauTagRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LibraryNiveauTagRepository::class)]
#[ORM\Table(name: 'library_niveau_tag')]
#[ORM\UniqueConstraint(name: 'library_niveau_tag_teacher_label', columns: ['teacher_id', 'label'])]
class LibraryNiveauTag extends AbstractLibraryTag
{
}
