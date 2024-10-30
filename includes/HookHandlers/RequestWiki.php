<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\HTMLForm\Field\HTMLToggleSwitchField;
use MediaWiki\User\User;
use MessageLocalizer;
use Miraheze\CreateWiki\Hooks\CreateWikiAfterCreationWithExtraDataHook;
use Miraheze\CreateWiki\Hooks\RequestWikiFormDescriptorModifyHook;
use Miraheze\CreateWiki\Hooks\RequestWikiQueueFormDescriptorModifyHook;
use Miraheze\CreateWiki\RequestWiki\FormFields\DetailsWithIconField;
use Miraheze\CreateWiki\RequestWiki\RequestWikiFormUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Miraheze\ManageWiki\Helpers\ManageWikiExtensions;
use Miraheze\ManageWiki\Helpers\ManageWikiSettings;

class RequestWiki implements
	CreateWikiAfterCreationWithExtraDataHook,
	RequestWikiFormDescriptorModifyHook,
	RequestWikiQueueFormDescriptorModifyHook
{

	private MessageLocalizer $messageLocalizer;
	private ServiceOptions $options;

	public function __construct(
		MessageLocalizer $messageLocalizer,
		ServiceOptions $options
	) {
		$this->messageLocalizer = $messageLocalizer;
		$this->options = $options;
	}

	public static function factory( Config $mainConfig ): self {
		return new self(
			RequestContext::getMain(),
			new ServiceOptions(
				[
					'ManageWikiExtensions',
					'MirahezeMagicRequestWikiExtensions',
					'MirahezeMagicRequestWikiSkins',
				],
				$mainConfig
			)
		);
	}

	public function onRequestWikiFormDescriptorModify( array &$formDescriptor ): void {
		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'nsfw',
			newField: [
				'label-message' => 'requestwiki-label-nsfw',
				'help-message' => 'requestwiki-help-nsfw',
				'help-inline' => false,
				'section' => 'request-info',
				'type' => 'check',
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'nsfwtext',
			newField: [
				'label-message' => 'requestwiki-label-nsfwtext',
				'hide-if' => [ '!==', 'nsfw', '1' ],
				'required' => true,
				'section' => 'request-info',
				'type' => 'text',
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'source',
			newField: [
				'label-message' => 'requestwiki-label-source',
				'section' => 'request-info',
				'type' => 'check',
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'sourceurl',
			newField: [
				'label-message' => 'requestwiki-label-sourceurl',
				'hide-if' => [ '!==', 'source', '1' ],
				'required' => true,
				'section' => 'request-info',
				'type' => 'url',
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'defaultskin',
			newField: [
				'type' => 'select',
				'label-message' => 'requestwiki-label-defaultskin',
				'options' => $this->buildLocalizedOptions(
					$this->options->get( 'MirahezeMagicRequestWikiSkins' )
				),
				'default' => 'vector-2022',
				'section' => 'configure',
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'wddescription',
			newField: [
				'type' => 'text',
				'maxlength' => 512,
				'label-message' => 'requestwiki-label-wddescription',
				'help-message' => 'requestwiki-help-wddescription',
				'section' => 'configure',
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'showadvanced',
			newField: [
				'class' => HTMLToggleSwitchField::class,
				'label-message' => 'requestwiki-label-showadvanced',
				'section' => 'advanced',
				// We handle this manually, we don't want to save this field
				'save' => false,
			]
		);

		/* RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'mainpageroot',
			newField: [
				'type' => 'check',
				'label-message' => 'miraheze-label-managewiki-mainpage-is-domain-root',
				'help-message' => 'miraheze-help-managewiki-mainpage-is-domain-root',
				'hide-if' => [ '!==', 'showadvanced', '1' ],
				'section' => 'advanced',
			]
		); */

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'articlepath',
			newField: [
				'type' => 'radio',
				'label-message' => 'miraheze-label-managewiki-article-path',
				'options-messages' => [
					'miraheze-label-managewiki-article-path-wiki' => '/wiki/$1',
					'miraheze-label-managewiki-article-path-root' => '/$1',
				],
				'hide-if' => [ '!==', 'showadvanced', '1' ],
				'default' => '/wiki/$1',
				'section' => 'advanced',
			]
		);

		if ( $this->options->get( 'MirahezeMagicRequestWikiExtensions' ) ) {
			RequestWikiFormUtils::addFieldToEnd(
				$formDescriptor,
				newKey: 'defaultextensions',
				newField: [
					'type' => 'multiselect',
					'label-message' => 'requestwiki-label-defaultextensions',
					'help-message' => 'requestwiki-help-defaultextensions',
					'help-inline' => false,
					'options' => $this->buildLocalizedOptions(
						$this->options->get( 'MirahezeMagicRequestWikiExtensions' )
					),
					'hide-if' => [ '!==', 'showadvanced', '1' ],
					'dropdown' => true,
					'section' => 'advanced',
				]
			);
		}

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'subdomain',
			newSection: 'core'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'sitename',
			newSection: 'core'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'language',
			newSection: 'core'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'category',
			newSection: 'configure'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'private',
			newSection: 'configure'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'guidance',
			newSection: 'request-info'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'purpose',
			newSection: 'request-info'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'reason',
			newSection: 'request-info'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'bio',
			newSection: 'request-info'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'post-reason-guidance',
			newSection: 'confirmation'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'agreement',
			newSection: 'confirmation'
		);

		// We use a more descriptive label message for
		// category, so we don't need the help message.
		RequestWikiFormUtils::unsetFieldProperty(
			$formDescriptor,
			fieldKey: 'category',
			propertyKey: 'help-message'
		);

		RequestWikiFormUtils::updateFieldProperties(
			$formDescriptor,
			fieldKey: 'category',
			newProperties: [ 'label-message' => 'requestwiki-label-category' ]
		);

		RequestWikiFormUtils::updateFieldProperties(
			$formDescriptor,
			fieldKey: 'subdomain',
			newProperties: [ 'help-inline' => false ]
		);

		RequestWikiFormUtils::updateFieldProperties(
			$formDescriptor,
			fieldKey: 'private',
			newProperties: [ 'help-inline' => false ]
		);

		RequestWikiFormUtils::updateFieldProperties(
			$formDescriptor,
			fieldKey: 'bio',
			newProperties: [ 'help-inline' => false ]
		);

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'core',
			newOrder: [
				'subdomain',
				'sitename',
				'language',
			]
		);

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'request-info',
			newOrder: [
				'guidance',
				'purpose',
				'bio',
				'nsfw',
				'source',
				'nsfwtext',
				'sourceurl',
				'reason',
			]
		);

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'configure',
			newOrder: [
				'private',
				'category',
				'defaultskin',
				'wddescription',
			]
		);

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'advanced',
			newOrder: [
				'showadvanced',
				// 'mainpageroot',
				'articlepath',
				'defaultextensions',
			]
		);

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'confirmation',
			newOrder: [
				'post-reason-guidance',
				'agreement',
			]
		);
	}

	public function onRequestWikiQueueFormDescriptorModify(
		array &$formDescriptor,
		User $user,
		WikiRequestManager $wikiRequestManager
	): void {
		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'edit-nsfw',
			newField: [
				'label-message' => 'requestwiki-label-nsfw',
				'section' => 'editing/request-info',
				'type' => 'check',
				'cssclass' => 'createwiki-infuse',
				'default' => $wikiRequestManager->getExtraFieldData( 'nsfw' ),
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'edit-nsfwtext',
			newField: [
				'label-message' => 'requestwiki-label-nsfwtext',
				'hide-if' => [ '!==', 'edit-nsfw', '1' ],
				'cssclass' => 'createwiki-infuse',
				'section' => 'editing/request-info',
				'required' => true,
				'type' => 'text',
				'default' => $wikiRequestManager->getExtraFieldData( 'nsfwtext' ),
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'edit-source',
			newField: [
				'label-message' => 'requestwiki-label-source',
				'section' => 'editing/request-info',
				'type' => 'check',
				'cssclass' => 'createwiki-infuse',
				'default' => $wikiRequestManager->getExtraFieldData( 'source' ),
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'edit-sourceurl',
			newField: [
				'label-message' => 'requestwiki-label-sourceurl',
				'hide-if' => [ '!==', 'edit-source', '1' ],
				'cssclass' => 'createwiki-infuse',
				'section' => 'editing/request-info',
				'required' => true,
				'type' => 'url',
				'default' => $wikiRequestManager->getExtraFieldData( 'sourceurl' ),
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'edit-defaultskin',
			newField: [
				'type' => 'select',
				'label-message' => 'requestwiki-label-defaultskin',
				'options' => $this->buildLocalizedOptions(
					$this->options->get( 'MirahezeMagicRequestWikiSkins' )
				),
				'section' => 'editing/configure',
				'cssclass' => 'createwiki-infuse',
				'default' => $wikiRequestManager->getExtraFieldData( 'defaultskin' ) ?? 'vector-2022',
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'edit-wddescription',
			newField: [
				'type' => 'text',
				'maxlength' => 512,
				'label-message' => 'requestwiki-label-wddescription',
				'section' => 'editing/configure',
				'cssclass' => 'createwiki-infuse',
				'default' => $wikiRequestManager->getExtraFieldData( 'wddescription' ),
			]
		);

		$isAdvancedModified = $wikiRequestManager->getExtraFieldData( 'mainpageroot' ) !== false ||
			$wikiRequestManager->getExtraFieldData( 'articlepath' ) !== '/wiki/$1' ||
			$wikiRequestManager->getExtraFieldData( 'defaultextensions' ) !== [];

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'edit-showadvanced',
			newField: [
				'class' => HTMLToggleSwitchField::class,
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'requestwiki-label-showadvanced',
				'section' => 'editing/advanced',
				'default' => $isAdvancedModified,
				'disabled' => $isAdvancedModified,
				// We handle this manually, we don't want to save this field
				'save' => false,
			]
		);

		/* RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'edit-mainpageroot',
			newField: [
				'type' => 'check',
				'label-message' => 'miraheze-label-managewiki-mainpage-is-domain-root',
				'hide-if' => [ '!==', 'edit-showadvanced', '1' ],
				'section' => 'editing/advanced',
				'cssclass' => 'createwiki-infuse',
				'default' => $wikiRequestManager->getExtraFieldData( 'mainpageroot' ),
			]
		); */

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'edit-articlepath',
			newField: [
				'type' => 'radio',
				'label-message' => 'miraheze-label-managewiki-article-path',
				'options-messages' => [
					'miraheze-label-managewiki-article-path-wiki' => '/wiki/$1',
					'miraheze-label-managewiki-article-path-root' => '/$1',
				],
				'hide-if' => [ '!==', 'edit-showadvanced', '1' ],
				'section' => 'editing/advanced',
				'cssclass' => 'createwiki-infuse',
				'default' => $wikiRequestManager->getExtraFieldData( 'articlepath' ) ?? '/wiki/$1',
			]
		);

		if ( $this->options->get( 'MirahezeMagicRequestWikiExtensions' ) ) {
			RequestWikiFormUtils::addFieldToEnd(
				$formDescriptor,
				newKey: 'edit-defaultextensions',
				newField: [
					'type' => 'multiselect',
					'label-message' => 'requestwiki-label-defaultextensions',
					'options' => $this->buildLocalizedOptions(
						$this->options->get( 'MirahezeMagicRequestWikiExtensions' )
					),
					'hide-if' => [ '!==', 'edit-showadvanced', '1' ],
					'dropdown' => true,
					'section' => 'editing/advanced',
					'cssclass' => 'createwiki-infuse',
					'default' => $wikiRequestManager->getExtraFieldData( 'defaultextensions' ),
				]
			);
		}

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'edit-url',
			newSection: 'editing/core'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'edit-sitename',
			newSection: 'editing/core'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'edit-language',
			newSection: 'editing/core'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'edit-category',
			newSection: 'editing/configure'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'edit-private',
			newSection: 'editing/configure'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'edit-purpose',
			newSection: 'editing/request-info'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'edit-reason',
			newSection: 'editing/request-info'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'edit-bio',
			newSection: 'editing/request-info'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'submit-edit',
			newSection: 'editing/advanced'
		);

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'editing/core',
			newOrder: [
				'edit-url',
				'edit-sitename',
				'edit-language',
			]
		);

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'editing/request-info',
			newOrder: [
				'edit-purpose',
				'edit-bio',
				'edit-nsfw',
				'edit-source',
				'edit-nsfwtext',
				'edit-sourceurl',
				'edit-reason',
			]
		);

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'editing/configure',
			newOrder: [
				'edit-private',
				'edit-category',
				'edit-defaultskin',
				'edit-wddescription',
			]
		);

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'editing/advanced',
			newOrder: [
				'edit-showadvanced',
				// 'edit-mainpageroot',
				'edit-articlepath',
				'edit-defaultextensions',
				// We put the edit button in advanced
				// just so it appears at the bottom.
				'submit-edit',
			]
		);

		RequestWikiFormUtils::reorderSections(
			$formDescriptor,
			// Everything else will be appended after
			newSectionOrder: [
				'details',
				'comments',
				'editing/core',
			]
		);

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'bio',
			newKey: 'nsfw',
			newField: [
				'class' => DetailsWithIconField::class,
				'label-message' => 'requestwiki-label-nsfw',
				'fieldCheck' => $wikiRequestManager->getExtraFieldData( 'nsfw' ),
				'section' => 'details',
			]
		);

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'nsfw',
			newKey: 'source',
			newField: [
				'class' => DetailsWithIconField::class,
				'label-message' => 'requestwiki-label-source',
				'fieldCheck' => $wikiRequestManager->getExtraFieldData( 'source' ),
				'section' => 'details',
			]
		);
	}

	public function onCreateWikiAfterCreationWithExtraData( array $extraData, string $dbname ): void {
		$mwSettings = new ManageWikiSettings( $dbname );
		$setList = $mwSettings->list();

		$mwExtensions = new ManageWikiExtensions( $dbname );
		$extList = $mwExtensions->list();

		if ( $extraData['articlepath'] !== ( $setList['wgArticlePath'] ?? '/wiki/$1' ) ) {
			$mwSettings->modify( [ 'wgArticlePath' => $extraData['articlepath'] ] );
			$mwSettings->commit();
		}

		/* if ( $extraData['mainpageroot'] !== ( $setList['wgMainPageIsDomainRoot'] ?? false ) ) {
			$mwSettings->modify( [ 'wgMainPageIsDomainRoot' => $extraData['mainpageroot'] ] );
			$mwSettings->commit();
		} */

		if ( $extraData['wddescription'] !== ( $setList['wgWikiDiscoverDescription'] ?? '' ) ) {
			$mwSettings->modify( [ 'wgWikiDiscoverDescription' => $extraData['wddescription'] ] );
			$mwSettings->commit();
		}

		if ( $extraData['defaultskin'] !== ( $setList['wgDefaultSkin'] ?? 'vector-2022' ) ) {
			if (
				!isset( $extList[ $extraData['defaultskin'] ] ) &&
				array_key_exists(
					$extraData['defaultskin'],
					$this->options->get( 'ManageWikiExtensions' )
				)
			) {
				$mwExtensions->add( $extraData['defaultskin'] );
				$mwExtensions->commit();
			}

			$mwSettings->modify( [ 'wgDefaultSkin' => $extraData['defaultskin'] ] );
			$mwSettings->commit();
		}

		if ( $extraData['defaultextensions'] ?? [] ) {
			$mwExtensions->add( $extraData['defaultextensions'] );
			$mwExtensions->commit();
		}
	}

	private function buildLocalizedOptions( array $options ): array {
		$localizedOptions = [];

		foreach ( $options as $key => $value ) {
			$localizedMessage = $this->messageLocalizer->msg( $key );

			if ( !$localizedMessage->isDisabled() ) {
				$localizedOptions[$localizedMessage->text()] = $value;
				continue;
			}

			$localizedOptions[$key] = $value;
		}

		return $localizedOptions;
	}
}
