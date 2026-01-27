// JavaScript Document
import { registerPageActions } from '../Core/pageActions.js';
import { getToken } from '../../Auth/auth.js';

let modalRoot;
let unregister;

export function mount({ modalRoot: root, data }) {
	modalRoot = root;
	if (data.user_id) {
		fetchUser(data.user_id);
	}
    unregister = registerPageActions({
        'users.save': () => {
            // validate
			alert("user ID " + data.user_id);
            // call API
            
      		bootstrap.Modal.getInstance(modalRoot).hide();
			//modalRoot?.modal.hide();
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
}

async function fetchUser(user_id) {
	const token = getToken();
	const savedBusinessId = localStorage.getItem('business_id');
	
	try {
		const res = await fetch('api/users/' + user_id, {
			method: 'GET',
    		credentials: 'include',
			headers: {
				'Authorization': `Bearer ${token}`,
				'X-Business-Id': savedBusinessId,
				'Accept': 'application/json'
			}
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
			document.getElementById('inputFirstName').value = user.name.split(' ')[0];
			document.getElementById('inputLastName').value = user.name.split(' ')[1];
			document.getElementById('inputEmailAddress').value = user.email;
			document.getElementById('inputUsername').value = user.username;
		}
	} catch (err){
        console.error('fetchUsers failed:', err);		
	}
}