<?php

namespace App\Form;

use App\Ai\Provider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ProviderChoice>
 */
final class ProviderChoiceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('provider', EnumType::class, [
                'class' => Provider::class,
                'expanded' => true,
                'choice_label' => static fn (Provider $provider): string => 'provider.'.$provider->value,
                'label' => false,
                'invalid_message' => 'error.choose_provider',
            ])
            ->add('continue', SubmitType::class, ['label' => 'action.continue']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProviderChoice::class]);
    }
}
