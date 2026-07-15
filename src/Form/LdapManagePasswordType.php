<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

// No data_class / mapped fields: the target user is picked through the tom-select ajax widget in
// directory/password_new.html.twig, submitted as a plain "user" field outside this form's own
// namespace and resolved server-side by App\Controller\DirectoryPasswordController - same
// reasoning as MessageComposeType's manual recipients (see that class's docblock). This form only
// exists to provide the CSRF token and submit button around that picker.
class LdapManagePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('submit', SubmitType::class, [
            'label' => 'submitCreateAction',
        ]);
    }
}
