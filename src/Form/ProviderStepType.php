<?php

namespace App\Form;

use App\Ai\Provider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ProviderStep>
 */
final class ProviderStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('provider', EnumType::class, [
                'class' => Provider::class,
                'expanded' => true,
                'choice_label' => static fn (Provider $provider): string => 'provider.'.$provider->value,
                'label' => 'models.provider',
                'invalid_message' => 'error.choose_provider',
            ])
            // Never sent back to the browser: the password field renders empty.
            ->add('apiKey', PasswordType::class, [
                'required' => false,
                'label' => 'models.api_key',
                'trim' => true,
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'autocapitalize' => 'off'],
            ])
            ->add('address', TextType::class, [
                'required' => false,
                'label' => 'models.address',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'autocapitalize' => 'off', 'inputmode' => 'url', 'placeholder' => 'http://host.docker.internal:PORT'],
            ])
            ->add('list', SubmitType::class, ['label' => 'action.list_models']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProviderStep::class]);
    }
}
