<?php

namespace App\Form;

use App\Game\Settings;
use App\Game\Theme;
use App\Game\Turns;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<GameSettingsData>
 */
final class GameSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $rounds = range(Settings::MIN_ROUNDS, Settings::MAX_ROUNDS);
        $seconds = Settings::ROUND_SECONDS;
        // A default set by the environment outside the usual choices stays a choice.
        $data = $builder->getData();
        if ($data instanceof GameSettingsData) {
            $rounds = array_values(array_unique([...$rounds, $data->rounds]));
            $seconds = array_values(array_unique([...$seconds, $data->roundSeconds]));
            sort($rounds);
            sort($seconds);
        }

        $builder
            ->add('themes', EnumType::class, [
                'class' => Theme::class,
                'multiple' => true,
                'expanded' => true,
                'choice_label' => static fn (Theme $theme): string => 'theme.'.$theme->value,
                'label' => 'settings.themes',
            ])
            ->add('turns', EnumType::class, [
                'class' => Turns::class,
                'expanded' => true,
                'choice_label' => static fn (Turns $turns): string => 'settings.turns_'.$turns->value,
                'label' => 'settings.turns',
            ])
            ->add('rounds', ChoiceType::class, [
                'choices' => array_combine($rounds, $rounds),
                'choice_translation_domain' => false,
                'label' => 'settings.rounds',
            ])
            ->add('roundSeconds', ChoiceType::class, [
                'choices' => array_combine(array_map(static fn (int $s): string => $s.' s', $seconds), $seconds),
                'expanded' => true,
                'choice_translation_domain' => false,
                'label' => 'settings.round_seconds',
            ])
            ->add('save', SubmitType::class, ['label' => 'action.save_settings']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => GameSettingsData::class]);
    }
}
