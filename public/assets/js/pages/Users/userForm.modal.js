// JavaScript Document
import { registerPageActions } from '../Core/pageActions.js';
import { confirm } from '../../components/modals.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let modalRoot;
let unregister;

const FIELDS = {
	modifyIdentityBtn: () => document.getElementById('modifyIdentity'),
	modalTitle: () => document.getElementById('modalTitle'),
	email: () => document.getElementById('inputEmailAddress'),
	username: () => document.getElementById('inputUsername'),
	firstName: () => document.getElementById('inputFirstName'),
	lastName: () => document.getElementById('inputLastName'),
	passwordResetBtn: () => document.getElementById('passwordReset'),
	password: () => document.getElementById('inputPassword'),
	confirmPassword: () => document.getElementById('inputConfirmPass'),
	role: () => document.getElementById('roleSelect'),
	isActive: () => document.getElementById('isActiveCheck'),
	saveBtn: () => document.getElementById('saveBtn')
};
const FORM_MODES = Object.freeze({
	LOOKUP: 'lookup',
	NEW_USER: 'new_user',
	EXISTING_USER: 'existing_user'
});
let passwordResetMode = false;
let modifyIdentity = false;
let currentMode = FORM_MODES.LOOKUP;
let user_id;

export async function mount({ modalRoot: root, data }) {
	modalRoot = root;
	fetchRoles();
    setupLookupListeners();
	if (data.user_id) {
		user_id = data.user_id;
		const user = await fetchUserMembership(data.user_id);
		fillIdentityFields(user);
		selectRole(user.role_id);
		setUserActiveState(user.is_active);
      	setMode(FORM_MODES.EXISTING_USER);
	} else {
    	setMode(FORM_MODES.LOOKUP);
	}
    unregister = registerPageActions({
		'users.modify.identity': async () => {
			const confirmed = await confirm({
				title: 'Modify Identity',
				message: 'This will allow identity modification. Continue?'
			});
			if(confirmed){
				// Password change allowed here
				modifyIdentity = true;
				setDisabled([
					FIELDS.email(),
					FIELDS.username(),
					FIELDS.firstName(),
					FIELDS.lastName()
				], false);

				FIELDS.modifyIdentityBtn().hidden = true;
			}				
		},
		'users.password.reset': async () => {
			const confirmed = await confirm({
				title: 'Password reset',
				message: 'This will reset the user’s password. Continue?'
			});
			if(confirmed){
				// Password change allowed here
				passwordResetMode = true;
				setDisabled([
					FIELDS.password(),
					FIELDS.confirmPassword()
				], false);

				FIELDS.passwordResetBtn().hidden = true;
			}				
		},
        'users.save': async () => {      		
            // validate
			if (!validateForm()) return;

			// 2️⃣ build payload
			const identity = {
				email: FIELDS.email().value.trim(),
				username: FIELDS.username().value.trim(),
				first_name: FIELDS.firstName().value.trim(),
				last_name: FIELDS.lastName().value.trim()
			};
			
			if (passwordResetMode || currentMode === FORM_MODES.NEW_USER) {
				identity.password = FIELDS.password().value;
			}
			
			const access = {
				role_id: parseInt(FIELDS.role().value, 10),
				is_active: FIELDS.isActive().checked ? 1 : 0
			};

			let url;
			let method;
			let payload;

			if (currentMode === FORM_MODES.EXISTING_USER){
				// PUT /users/{id}
				url = `/api/users/${user_id}`;
				method = 'PUT';
				payload = { ...identity, ...access };
			}
			else{		
				// POST /users
				url = '/api/users';
				method = 'POST';
				payload = { ...identity, ...access };
			}
            // call API
			
			try {
				const res = await apiFetch(url, {
					method,
					body: JSON.stringify(payload)
				});

				const json = await res.json();

				if (!res.ok || !json.success) {
					throw new Error(json.message || 'Operation failed');
				}
            
				bootstrap.Modal.getInstance(modalRoot).hide();
				
				document.dispatchEvent(new CustomEvent('users.updated'));
				const selfModified = !!json?.data?.selfModified;
				if (selfModified){
					document.dispatchEvent(new CustomEvent('users.selfModified'));
				}

			} catch (err) {
				console.error(err);
				showFormError(err.message || 'Unable to save user');
			}
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
}
	
async function fetchRoles(){
    try {
        const res = await apiFetch('/api/roles', { method: 'GET' });
        if (!res.ok) throw new Error(`HTTP error ${res.status}`);

        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Failed to fetch roles');

        const select = document.getElementById('roleSelect');
        if (!select) return;

        // Clear all options except the placeholder (first option)
        while (select.options.length > 1) {
            select.remove(1);
        }

        const rolesData = data.data.roles;
        const businessRoles = rolesData.business_roles || [];
        const templateRoles = rolesData.template_roles || [];

        businessRoles.forEach(role => {
            const option = document.createElement('option');
            option.value = role.id;
            option.textContent = role.name;
            select.appendChild(option);
        });

        templateRoles.forEach(role => {
            const option = document.createElement('option');
            option.value = role.id;
            option.textContent = `${role.name} (template)`;
            select.appendChild(option);
        });
    } catch (err) {
        console.error('fetchRoles failed:', err);
    }
}
	
function setMode(mode) {
  currentMode = mode;

  switch (mode) {
    case FORM_MODES.LOOKUP:
      enterLookupMode();
      break;

    case FORM_MODES.NEW_USER:
      enterNewUserMode();
      break;

    case FORM_MODES.EXISTING_USER:
      enterExistingUserMode();
      break;

    default:
      console.warn('Unknown form mode:', mode);
  }
}
	
function enterLookupMode() {
	setDisabled([
		FIELDS.firstName(),
		FIELDS.lastName(),
		FIELDS.password(),
		FIELDS.confirmPassword(),
		FIELDS.role(),
		FIELDS.isActive(),
		FIELDS.saveBtn()
	], true);
	
	FIELDS.passwordResetBtn().hidden = true;
	FIELDS.modifyIdentityBtn().hidden = true;

	setDisabled([
		FIELDS.email(),
		FIELDS.username()
	], false);

	FIELDS.isActive().checked = true;
	
	FIELDS.modalTitle().innerHTML = "Create User";
	
	setLookupFeedback(
	  'Enter email or username to search for an existing user.'
	);
}

function setLookupFeedback(message, type = 'info') {
	const container = document.getElementById('lookupFields');
	const feedback = document.getElementById('lookupFeedback');

	if (!container || !feedback) return;

	// Reset states
	container.classList.remove('lookup-info', 'lookup-success', 'lookup-error');
	feedback.className = 'form-text';

	if (!message) {
		feedback.textContent = '';
		return;
	}

	feedback.textContent = message;

	if (type === 'success') {
		container.classList.add('lookup-success');
		feedback.classList.add('text-success');
	} else if (type === 'error') {
		container.classList.add('lookup-error');
		feedback.classList.add('text-danger');
	} else {
		feedback.classList.add('text-muted');
	}
}
	
function enterNewUserMode() {
	setDisabled([
		FIELDS.email(),
		FIELDS.username(),
		FIELDS.firstName(),
		FIELDS.lastName(),
		FIELDS.password(),
		FIELDS.confirmPassword(),
		FIELDS.role(),
		FIELDS.isActive(),
		FIELDS.saveBtn()
	], false);
	
	FIELDS.passwordResetBtn().hidden = true;
	FIELDS.modifyIdentityBtn().hidden = true;

	FIELDS.isActive().checked = true;
	
	FIELDS.modalTitle().innerHTML = "Create User";
}

function enterExistingUserMode() {
	setDisabled([
		FIELDS.email(),
		FIELDS.username(),
		FIELDS.firstName(),
		FIELDS.lastName()
	], true);

	setDisabled([
		FIELDS.role(),
		FIELDS.isActive(),
		FIELDS.saveBtn()
	], false);

	// Password change not allowed here
	setDisabled([
		FIELDS.password(),
		FIELDS.confirmPassword()
	], true);
	
	FIELDS.passwordResetBtn().hidden = false;
	FIELDS.modifyIdentityBtn().hidden = false;
	
	FIELDS.modalTitle().innerHTML = "Edit user access";
}

function setDisabled(elements, disabled) {
  elements.forEach(el => {
    if (el) el.disabled = disabled;
  });
}

async function fetchUserMembership(user_id) {	
	try {
		const res = await apiFetch(`/api/users/${user_id}`, {
			method: 'GET'
		});

        if (!res.ok) {
            throw new Error(`HTTP error ${res.status}`);
        }

		const data = await res.json();
		
		if (!data.success){
            console.error(data.message || 'Failed to fetch users');
            return;			
		} else{
			const user = data.data.user;
			// Show account status indicator in edit mode
			showAccountStatusIndicator(user.is_account_active);
			return user;
		}
	} catch (err){
        console.error('fetchUsers failed:', err);		
	}
}

function showAccountStatusIndicator(isAccountActive) {
	const indicator = document.getElementById('accountStatusIndicator');
	const badge = document.getElementById('accountStatusBadge');
	const hint = document.getElementById('accountStatusHint');
	if (!indicator || !badge || !hint) return;

	indicator.classList.remove('d-none');

	if (isAccountActive) {
		badge.className = 'badge bg-success';
		badge.textContent = 'Active';
		hint.textContent = 'This user can log in. The toggle below controls this business only.';
	} else {
		badge.className = 'badge bg-danger';
		badge.textContent = 'Disabled';
		hint.textContent = 'Account is disabled globally. User cannot log in to any business until re-enabled from the users table.';
	}
}

function selectRole(roleId) {
  const select = document.getElementById('roleSelect');
  if (!select) return;

  const value = String(roleId);

  if (select.value !== value) {
    select.value = value;
  }
}

function setUserActiveState(is_active) {
  const cb = document.getElementById('isActiveCheck');
  if (!cb) {
    console.warn('isActiveCheck not found');
    return;
  }

  cb.checked = is_active === 1 || is_active === '1';
}

function validateForm() {
	clearValidation();

	let valid = true;

	const email = FIELDS.email();
	const username = FIELDS.username();
	const firstName = FIELDS.firstName();
	const lastName = FIELDS.lastName();
	const password = FIELDS.password();
	const confirmPassword = FIELDS.confirmPassword();
	const role = FIELDS.role();

	// Email OR username required
	if (!email.value.trim()) {
		markInvalid(email, 'Email is required');
		valid = false;
	}
	if (!username.value.trim()) {
		markInvalid(username, 'Username is required');
		valid = false;
	}

	// Role required
	if (!role.value) {
		markInvalid(role, 'Role is required');
		valid = false;
	}

	// New user-specific validation
	const isNewUser = currentMode === FORM_MODES.NEW_USER;
	if (isNewUser) {
		if (!firstName.value.trim()) {
			markInvalid(firstName, 'First name is required');
			valid = false;
		}

		if (!lastName.value.trim()) {
			markInvalid(lastName, 'Last name is required');
			valid = false;
		}
	}
	
	// Password reset
	if(isNewUser || passwordResetMode){
		if (!password.value) {
			markInvalid(password, 'Password is required');
			valid = false;
		}

		if (!confirmPassword.value) {
			markInvalid(confirmPassword, 'Please confirm the password');
			valid = false;
		}
		
		if (password.value && confirmPassword.value && password.value !== confirmPassword.value) {
			markInvalid(confirmPassword, 'Passwords do not match');
			valid = false;
		}
	}

	return valid;
}

function setupLookupListeners() {	
	modalRoot.addEventListener('input', e => {
		if (e.target.classList.contains('is-invalid')) {
			e.target.classList.remove('is-invalid');
			const feedback = e.target.parentElement.querySelector('.invalid-feedback');
			if (feedback) feedback.remove();
		}
	});
	const email = FIELDS.email();
	const username = FIELDS.username();

	if (email) {
		email.addEventListener('blur', handleLookup);
	}

	if (username) {
		username.addEventListener('blur', handleLookup);
	}
}

async function handleLookup() {
  if (currentMode !== FORM_MODES.LOOKUP) return;

  const email = FIELDS.email().value.trim();
  const username = FIELDS.username().value.trim();

  if (!email && !username) return;

  const user = await lookupUser(email, username);

  if (user) {
	  fillIdentityFields(user);
	  setMode(FORM_MODES.EXISTING_USER);
	  const membership = await fetchUserMembership(user.user_id);
	  if (membership) {
		  // User already belongs to this business
		  selectRole(membership.role_id);
		  setUserActiveState(membership.is_active);
	  }
	  user_id = user.user_id;
	  setLookupFeedback(
		  'Existing user found. You can assign or modify the role.',
		  'success'
	  );
  } else {
	  setMode(FORM_MODES.NEW_USER);
	  setLookupFeedback(
		  'No existing user found. A new user will be created.',
		  'info'
	  );
  }
}

async function lookupUser(email, username) {
	const params = new URLSearchParams();

	if (email) params.append('email', email);
	if (username) params.append('username', username);

	const res = await apiFetch(`/api/users/lookup?${params}`);

	if (!res.ok) return null;

	const data = await res.json();
	if (!data.success) return null;

	return data.data.exists ? data.data.user : null;
}

function fillIdentityFields(user){	
	document.getElementById('inputFirstName').value = user.first_name;
	document.getElementById('inputLastName').value = user.last_name;
	document.getElementById('inputEmailAddress').value = user.email;
	document.getElementById('inputUsername').value = user.username;
}

function clearValidation(formRoot = modalRoot) {
	formRoot.querySelectorAll('.is-invalid').forEach(el => {
		el.classList.remove('is-invalid');
	});

	formRoot.querySelectorAll('.invalid-feedback').forEach(el => {
		el.remove();
	});
}

function markInvalid(input, message) {
	if (!input) return;

	input.classList.add('is-invalid');

	// Avoid duplicating messages
	let feedback = input.parentElement.querySelector('.invalid-feedback');
	if (!feedback) {
		feedback = document.createElement('div');
		feedback.className = 'invalid-feedback';
		input.parentElement.appendChild(feedback);
	}

	feedback.textContent = message;
}

function showFormError(message) {
	const footer = modalRoot.querySelector('.modal-footer');
	let el = footer.querySelector('.form-error');

	if (!el) {
		el = document.createElement('div');
		el.className = 'form-error text-danger me-auto';
		footer.prepend(el);
	}

	el.textContent = message;
}
