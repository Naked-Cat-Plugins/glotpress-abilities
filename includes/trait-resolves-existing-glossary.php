<?php
/**
 * Shared glossary lookup for abilities that change existing glossary entries.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the glossary an edit or delete applies to, with the same scope rules as
 * nakedcat-glotpress/add-glossary-entries: the locale's global glossary by default, or with a
 * project path that project's own glossary (never a parent project's one).
 *
 * Unlike add-glossary-entries, nothing is ever created here: changing entries needs a glossary
 * that already holds them.
 */
trait Resolves_Existing_Glossary {

	/**
	 * Resolves an existing glossary for the scope given.
	 *
	 * @param string $locale_slug          The GlotPress locale slug.
	 * @param string $project_path         The project path, or '' for the global glossary.
	 * @param string $translation_set_slug The translation set variant slug.
	 * @return \GP_Glossary|\WP_Error
	 */
	private static function resolve_existing_glossary( $locale_slug, $project_path, $translation_set_slug ) {
		if ( ! \GP_Locales::by_slug( $locale_slug ) ) {
			return new \WP_Error(
				'nakedcat_glotpress_locale_not_found',
				sprintf(
					/* translators: %s: GlotPress locale slug. */
					__( 'No GlotPress locale was found with slug "%s".', 'nakedcat-glotpress-abilities' ),
					$locale_slug
				)
			);
		}

		$project_id = 0;

		if ( '' !== $project_path ) {
			$project = \GP::$project->by_path( $project_path );

			if ( ! $project ) {
				return new \WP_Error(
					'nakedcat_glotpress_project_not_found',
					sprintf(
						/* translators: %s: GlotPress project path. */
						__( 'No GlotPress project was found at path "%s".', 'nakedcat-glotpress-abilities' ),
						$project_path
					)
				);
			}

			$project_id = (int) $project->id;
		}

		// find_one() rather than by_project_id_slug_and_locale(), which creates the global set.
		$translation_set = \GP::$translation_set->find_one(
			array(
				'project_id' => $project_id,
				'slug'       => $translation_set_slug,
				'locale'     => $locale_slug,
			)
		);

		$glossary = $translation_set ? \GP::$glossary->by_set_id( $translation_set->id ) : null;

		if ( ! $glossary ) {
			return new \WP_Error(
				'nakedcat_glotpress_glossary_not_found',
				'' === $project_path
					? __( 'This locale has no global glossary.', 'nakedcat-glotpress-abilities' )
					: sprintf(
						/* translators: %s: GlotPress project path. */
						__( 'Project "%s" has no glossary of its own for this locale.', 'nakedcat-glotpress-abilities' ),
						$project_path
					)
			);
		}

		return $glossary;
	}

	/**
	 * Fetches a glossary entry by ID, only if it belongs to the given glossary.
	 *
	 * @param \GP_Glossary $glossary The glossary.
	 * @param int          $entry_id The entry ID.
	 * @return \GP_Glossary_Entry|null
	 */
	private static function entry_in_glossary( $glossary, $entry_id ) {
		$entry = $entry_id ? \GP::$glossary_entry->get( $entry_id ) : null;

		return ( $entry && (int) $entry->glossary_id === (int) $glossary->id ) ? $entry : null;
	}
}
