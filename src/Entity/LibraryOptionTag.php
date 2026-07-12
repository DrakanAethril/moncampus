<?php

namespace App\Entity;

use App\Repository\LibraryOptionTagRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LibraryOptionTagRepository::class)]
#[ORM\Table(name: 'library_option_tag')]
#[ORM\UniqueConstraint(name: 'library_option_tag_teacher_label', columns: ['teacher_id', 'label'])]
class LibraryOptionTag extends AbstractLibraryTag
{
}
