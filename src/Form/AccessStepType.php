<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AccessStep>
 */
final class AccessStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['with_address']) {
            $builder->add('address', TextType::class, [
                'required' => false,
                'label' => 'models.address',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'autocapitalize' => 'off', 'inputmode' => 'url', 'placeholder' => 'http://host.docker.internal:PORT'],
            ]);
        }
        // Never sent back to the browser: the password field renders empty.
        $builder
            ->add('apiKey', PasswordType::class, [
                'required' => false,
                'label' => $options['key_label'],
                'label_translation_parameters' => $options['key_label_parameters'],
                'trim' => true,
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'autocapitalize' => 'off'],
            ])
            ->add('continue', SubmitType::class, ['label' => 'action.continue']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccessStep::class,
            'with_address' => false,
            'key_label' => 'models.api_key',
            'key_label_parameters' => [],
        ]);
        $resolver->setAllowedTypes('with_address', 'bool');
        $resolver->setAllowedTypes('key_label', 'string');
        $resolver->setAllowedTypes('key_label_parameters', 'array');
    }
}
