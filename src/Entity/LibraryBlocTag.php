<?php

namespace App\Entity;

use App\Repository\LibraryBlocTagRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LibraryBlocTagRepository::class)]
#[ORM\Table(name: 'library_bloc_tag')]
#[ORM\UniqueConstraint(name: 'library_bloc_tag_teacher_label', columns: ['teacher_id', 'label'])]
class LibraryBlocTag extends AbstractLibraryTag
{
}
