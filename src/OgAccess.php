<?php

/**
 * @file
 * Contains \Drupal\og\OgAccess.
 */

namespace Drupal\og;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\RoleInterface;

/**
 * The service that determines if users have access to groups and group content.
 */
class OgAccess implements OgAccessInterface {

  /**
   * Administer permission string.
   *
   * @var string
   */
  const ADMINISTER_GROUP_PERMISSION = 'administer group';

  /**
   * Update group permission string.
   *
   * @var string
   */
  const UPDATE_GROUP_PERMISSION = 'update group';

  /**
   * Static cache that contains cache permissions.
   *
   * @var array
   *   Array keyed by the following keys:
   *   - alter: The permissions after altered by implementing modules.
   *   - pre_alter: The pre-altered permissions, as read from the config.
   */
  protected $permissionsCache = [];

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The service that contains the current active user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $accountProxy;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The group manager.
   *
   * @var \Drupal\og\GroupManager
   *
   * @todo This should be GroupManagerInterface.
   */
  protected $groupManager;

  /**
   * The OG permission manager.
   *
   * @var \Drupal\og\PermissionManagerInterface
   */
  protected $permissionManager;

  /**
   * Constructs an OgManager service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $account_proxy
   *   The service that contains the current active user.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\og\GroupManager
   *   The group manager.
   * @param \Drupal\og\PermissionManagerInterface $permission_manager
   *   The permission manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, AccountProxyInterface $account_proxy, ModuleHandlerInterface $module_handler, GroupManager $group_manager, PermissionManagerInterface $permission_manager) {
    $this->configFactory = $config_factory;
    $this->accountProxy = $account_proxy;
    $this->moduleHandler = $module_handler;
    $this->groupManager = $group_manager;
    $this->permissionManager = $permission_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function userAccess(EntityInterface $group, $operation, AccountInterface $user = NULL, $skip_alter = FALSE, $ignore_admin = FALSE) {
    $group_type_id = $group->getEntityTypeId();
    $bundle = $group->bundle();
    // As Og::isGroup depends on this config, we retrieve it here and set it as
    // the minimal caching data.
    $config = $this->configFactory->get('og.settings');
    $cacheable_metadata = (new CacheableMetadata)
        ->addCacheableDependency($config);
    if (!Og::isGroup($group_type_id, $bundle)) {
      // Not a group.
      return AccessResult::neutral()->addCacheableDependency($cacheable_metadata);
    }

    if (!isset($user)) {
      $user = $this->accountProxy->getAccount();
    }

    // From this point on, every result also depends on the user so check
    // whether it is the current. See https://www.drupal.org/node/2628870
    if ($user->id() == $this->accountProxy->id()) {
      $cacheable_metadata->addCacheContexts(['user']);
    }

    // User ID 1 has all privileges.
    if ($user->id() == 1) {
      return AccessResult::allowed()->addCacheableDependency($cacheable_metadata);
    }

    // Administer group permission.
    if (!$ignore_admin) {
      $user_access = AccessResult::allowedIfHasPermission($user, self::ADMINISTER_GROUP_PERMISSION);
      if ($user_access->isAllowed()) {
        return $user_access->addCacheableDependency($cacheable_metadata);
      }
    }

    // Update group special permission. At this point, the operation should have
    // already been handled by Og. If the operation is edit, it is referring to
    // the current group, so we have to map it to the special permission.
    // This map occurs here and not in the userEntityAccess because all checks
    // should pass through this function.
    if ($operation == 'edit') {
      $operation = OgAccess::UPDATE_GROUP_PERMISSION;
    }

    // Group manager has all privileges (if variable is TRUE) and they are
    if ($config->get('group_manager_full_access') && $user->isAuthenticated() && $group instanceof EntityOwnerInterface) {
      $cacheable_metadata->addCacheableDependency($group);
      if ($group->getOwnerId() == $user->id()) {
        return AccessResult::allowed()->addCacheableDependency($cacheable_metadata);
      }
    }

    $pre_alter_cache = $this->getPermissionsCache($group, $user, TRUE);
    $post_alter_cache = $this->getPermissionsCache($group, $user, FALSE);

    // To reduce the number of SQL queries, we cache the user's permissions.
    if (!$pre_alter_cache) {
      $permissions = [];
      $user_is_group_admin = FALSE;

      if ($membership = Og::getMembership($user, $group)) {
        foreach ($membership->getRoles() as $role) {
          // Check for the is_admin flag.
          if ($role->isAdmin()) {
            $user_is_group_admin = TRUE;
            break;
          }

          /** @var $role RoleInterface */
          $permissions = array_merge($permissions, $role->getPermissions());
        }
      }

      $permissions = array_unique($permissions);

      $this->setPermissionCache($group, $user, TRUE, $permissions, $user_is_group_admin, $cacheable_metadata);
    }

    if (!$skip_alter && !in_array($operation, $post_alter_cache)) {
      // Let modules alter the permissions. So we get the original ones, and
      // pass them along to the implementing modules.
      $alterable_permissions = $this->getPermissionsCache($group, $user, TRUE);

      $context = array(
        'operation' => $operation,
        'group' => $group,
        'user' => $user,
      );
      $this->moduleHandler->alter('og_user_access', $alterable_permissions['permissions'], $cacheable_metadata, $context);

      $this->setPermissionCache($group, $user, FALSE, $alterable_permissions['permissions'], $alterable_permissions['is_admin'], $cacheable_metadata);
    }

    $altered_permissions = $this->getPermissionsCache($group, $user, FALSE);

    $user_is_group_admin = !empty($altered_permissions['is_admin']);

    if (($user_is_group_admin && !$ignore_admin) || in_array($operation, $altered_permissions['permissions'])) {
      // User is a group admin, and we do not ignore this special permission
      // that grants access to all the group permissions.
      return AccessResult::allowed()->addCacheableDependency($altered_permissions['cacheable_metadata']);
    }

    return AccessResult::forbidden()->addCacheableDependency($cacheable_metadata);
  }

  /**
   * {@inheritdoc}
   */
  public function userAccessEntity($operation, EntityInterface $entity, AccountInterface $user = NULL) {
    $result = AccessResult::neutral();

    // Entity isn't saved yet.
    // @todo This messes with checking access to create group content, since in
    //   this case the content is not saved yet. This will now always return
    //   neutral, but it should check if the user has permission to create a new
    //   entity..
//    if ($entity->isNew()) {
//      return $result->addCacheableDependency($entity);
//    }

    $entity_type = $entity->getEntityType();
    $entity_type_id = $entity_type->id();
    $bundle = $entity->bundle();

    if (Og::isGroup($entity_type_id, $bundle)) {
      $user_access = $this->userAccess($entity, $operation, $user);
      if ($user_access->isAllowed()) {
        return $user_access;
      }
      else {
        // An entity can be a group and group content in the same time. The
        // group didn't allow access, but the user still might have access to
        // the permission in group content context. So instead of retuning a
        // deny here, we set the result, that might change if an access is
        // found.
        $result = AccessResult::forbidden()->inheritCacheability($user_access);
      }
    }

    // @TODO: add caching on Og::isGroupContent.
    $is_group_content = Og::isGroupContent($entity_type_id, $bundle);
    $cache_tags = $entity_type->getListCacheTags();

    // The entity might be a user or a non-user entity.
    $groups = $entity->getEntityTypeId() == 'user' ? Og::getUserGroups($entity) : Og::getGroups($entity);

    if ($is_group_content && $groups) {
      $forbidden = AccessResult::forbidden()->addCacheTags($cache_tags);
      foreach ($groups as $entity_groups) {
        foreach ($entity_groups as $group) {
          $operation_access = $this->userAccessGroupContentEntityOperations($operation, $group, $entity, $user);
          if (!empty($operation_access) && $operation_access->isAllowed()) {
            return $operation_access->addCacheTags($cache_tags);
          }
          $user_access = $this->userAccess($group, $operation, $user);
          if (!empty($user_access) && $user_access->isAllowed()) {
            return $user_access->addCacheTags($cache_tags);
          }

          $forbidden->inheritCacheability($user_access);
        }
      }
      return $forbidden;
    }
    if ($is_group_content) {
      $result->addCacheTags($cache_tags);
    }

    // Either the user didn't have permission, or the entity might be an
    // orphaned group content.
    return $result;
  }

  /**
   * Checks access for entity operations on group content entities.
   *
   * This checks if the user has permission to perform the requested operation
   * on the given group content entity according to the user's membership status
   * in the given group. There is no formal support for access control on entity
   * operations in core, so the mapping of permissions to operations is provided
   * by PermissionManager::getEntityOperationPermissions().
   *
   * @param string $operation
   *   The entity operation.
   * @param \Drupal\Core\Entity\EntityInterface $group_entity
   *   The group entity, to retrieve the permissions from.
   * @param \Drupal\Core\Entity\EntityInterface $group_content_entity
   *   The group content entity for which access to the entity operation is
   *   requested.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user for which to check access.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result object.
   *
   * @see \Drupal\og\PermissionManager::getEntityOperationPermissions()
   */
  public function userAccessGroupContentEntityOperations($operation, EntityInterface $group_entity, EntityInterface $group_content_entity, AccountInterface $user = NULL) {
    // Retrieve the permissions and check if our operation is supported.
    $group_entity_type_id = $group_entity->getEntityTypeId();
    $group_bundle_id = $group_entity->bundle();
    $group_content_bundle_ids = [$group_content_entity->getEntityTypeId() => [$group_content_entity->bundle()]];

    // Default to the current user.
    if (!isset($user)) {
      $user = $this->accountProxy->getAccount();
    }


    $is_owner = $group_content_entity instanceof EntityOwnerInterface && $group_content_entity->getOwnerId() == $user->id();

    $permissions = $this->permissionManager->getDefaultEntityOperationPermissions($group_entity_type_id, $group_bundle_id, $group_content_bundle_ids);

    // Filter the permissions by operation and ownership.
    $ownerships = $is_owner ? ['any', 'own'] : ['any'];
    $permissions = array_filter($permissions, function (GroupContentOperationPermission $permission) use ($operation, $ownerships) {
      return $permission->getOperation() === $operation && in_array($permission->getOwnership(), $ownerships);
    });

    // @todo Should we make a cache context for OgRole entities?
    $cacheable_metadata = new CacheableMetadata;
    $cacheable_metadata->addCacheableDependency($group_content_entity);
    if ($user->id() == $this->accountProxy->id()) {
      $cacheable_metadata->addCacheContexts(['user']);
    }

    // @todo Also deal with the use case that entity operations are granted to
    //   non-members.
    if ($membership = Og::getMembership($user, $group_entity)) {
      foreach ($permissions as $permission) {
        if ($membership->hasPermission($permission->getName())) {
          return AccessResult::allowed()->addCacheableDependency($cacheable_metadata);
        }
      }
    }

    return AccessResult::neutral()->addCacheableDependency($cacheable_metadata);
  }

  /**
   * Set the permissions in the static cache.
   *
   * @param \Drupal\Core\Entity\EntityInterface $group
   *   The entity object.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user object.
   * @param bool $pre_alter $type
   *   Determines if the type of permissions is pre-alter or post-alter.
   * @param array $permissions
   *   Array of permissions to set.
   * @param bool @is_admin
   *   Whether or not the user is a group administrator.
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $cacheable_metadata
   *   A cacheable metadata object.
   */
  protected function setPermissionCache(EntityInterface $group, AccountInterface $user, $pre_alter, array $permissions, $is_admin, RefinableCacheableDependencyInterface $cacheable_metadata) {
    $entity_type_id = $group->getEntityTypeId();
    $group_id = $group->id();
    $user_id = $user->id();
    $type = $pre_alter ? 'pre_alter' : 'post_alter';

    $this->permissionsCache[$entity_type_id][$group_id][$user_id][$type] = [
      'is_admin' => $is_admin,
      'permissions' => $permissions,
      'cacheable_metadata' => $cacheable_metadata,
    ];
  }

  /**
   * Get the permissions from the static cache.
   *
   * @param \Drupal\Core\Entity\EntityInterface $group
   *   The entity object.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user object.
   * @param bool $pre_alter $type
   *   Determines if the type of permissions is pre-alter or post-alter.
   *
   * @return array
   *   Array of permissions if cached, or an empty array.
   */
  protected function getPermissionsCache(EntityInterface $group, AccountInterface $user, $pre_alter) {
    $entity_type_id = $group->getEntityTypeId();
    $group_id = $group->id();
    $user_id = $user->id();
    $type = $pre_alter ? 'pre_alter' : 'post_alter';

    return isset($this->permissionsCache[$entity_type_id][$group_id][$user_id][$type]) ?
      $this->permissionsCache[$entity_type_id][$group_id][$user_id][$type] :
      [];
  }

  /**
   * {@inheritdoc}
   */
  public function reset() {
    $this->permissionsCache = [];
  }

}
