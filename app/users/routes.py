from flask import request, jsonify
from flask_jwt_extended import jwt_required, get_jwt_identity
from app import db
from app.models import User, UserProfile, Skill
from app.users import bp

# Get user profile (protected)
@bp.route('/profile', methods=['GET'])
@jwt_required()
def get_profile():
    current_user_id = get_jwt_identity()
    user = User.query.get_or_404(current_user_id)
    profile = UserProfile.query.filter_by(user_id=current_user_id).first_or_404()
    user_skills = [skill.name for skill in user.skills]

    return jsonify({
        'username': user.username,
        'email': user.email,
        'first_name': profile.first_name,
        'last_name': profile.last_name,
        'bio': profile.bio,
        'location': profile.location,
        'profile_picture_url': profile.profile_picture_url,
        'skills': user_skills,
        'credits': user.credits.balance if user.credits else 0,
        'member_since': user.registration_date.isoformat()
    }), 200

# Update user profile (protected)
@bp.route('/profile', methods=['PUT'])
@jwt_required()
def update_profile():
    current_user_id = get_jwt_identity()
    profile = UserProfile.query.filter_by(user_id=current_user_id).first_or_404()
    data = request.get_json()

    # Update fields if provided in the request data
    profile.first_name = data.get('first_name', profile.first_name)
    profile.last_name = data.get('last_name', profile.last_name)
    profile.bio = data.get('bio', profile.bio)
    profile.location = data.get('location', profile.location)
    profile.profile_picture_url = data.get('profile_picture_url', profile.profile_picture_url)

    db.session.commit()
    return jsonify({"message": "Profile updated successfully"}), 200

# Add a skill to user profile (protected)
@bp.route('/skills', methods=['POST'])
@jwt_required()
def add_user_skill():
    current_user_id = get_jwt_identity()
    user = User.query.get_or_404(current_user_id)
    data = request.get_json()

    skill_name = data.get('skill_name')
    if not skill_name:
        return jsonify({"message": "Skill name is required"}), 400

    # Find skill or create if it doesn't exist (optional, depends on requirements)
    skill = Skill.query.filter_by(name=skill_name).first()
    if not skill:
        # If you want users to only add existing skills, return an error here
        # return jsonify({"message": f"Skill '{skill_name}' not found"}), 404
        # Or, if users can implicitly create skills by adding them:
        skill = Skill(name=skill_name, category=data.get('category', 'Uncategorized')) # Add category if provided
        db.session.add(skill)
        # You might want to flush here if you need the skill ID immediately

    if skill in user.skills:
        return jsonify({"message": f"User already has skill '{skill_name}'"}), 400

    user.skills.append(skill)
    db.session.commit()

    return jsonify({"message": f"Skill '{skill_name}' added to profile"}), 201

# Remove a skill from user profile (protected)
@bp.route('/skills/<string:skill_name>', methods=['DELETE'])
@jwt_required()
def remove_user_skill(skill_name):
    current_user_id = get_jwt_identity()
    user = User.query.get_or_404(current_user_id)
    skill = Skill.query.filter_by(name=skill_name).first()

    if not skill or skill not in user.skills:
        return jsonify({"message": f"Skill '{skill_name}' not found in user profile"}), 404

    user.skills.remove(skill)
    db.session.commit()

    return jsonify({"message": f"Skill '{skill_name}' removed from profile"}), 200

# Get another user's public profile (public route)
@bp.route('/<int:user_id>/profile', methods=['GET'])
def get_public_profile(user_id):
    user = User.query.get_or_404(user_id)
    profile = UserProfile.query.filter_by(user_id=user_id).first_or_404()
    user_skills = [skill.name for skill in user.skills]
    # Add logic to calculate average rating if needed

    return jsonify({
        'username': user.username,
        'first_name': profile.first_name,
        'last_name': profile.last_name,
        'bio': profile.bio,
        'location': profile.location,
        'profile_picture_url': profile.profile_picture_url,
        'skills': user_skills,
        'member_since': user.registration_date.isoformat()
        # Add average_rating here if calculated
    }), 200 