// app/Http/Requests/LoginRequest.php
public function rules()
{
    return [
        'email' => 'required|email|max:255',
        'password' => 'required|string|min:8',
    ];
}

public function messages()
{
    return [
        'email.required' => 'L\'email est obligatoire.',
        'email.email' => 'L\'email doit être valide.',
        'password.required' => 'Le mot de passe est obligatoire.',
        'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
    ];
}