from flask import request, jsonify
from flask_jwt_extended import jwt_required # Skills routes might be protected
from app import db
from app.models import Skill
from app.skills import bp

# Get all skills (public or protected)
@bp.route('', methods=['GET'])
# @jwt_required() # Uncomment if only logged-in users can view skills
def get_skills():
    # Add pagination if the list can grow large
    skills = Skill.query.all()
    return jsonify([{
        'id': skill.id,
        'name': skill.name,
        'description': skill.description,
        'category': skill.category
    } for skill in skills]), 200

# Get a specific skill by ID (public or protected)
@bp.route('/<int:skill_id>', methods=['GET'])
# @jwt_required()
def get_skill(skill_id):
    skill = Skill.query.get_or_404(skill_id)
    return jsonify({
        'id': skill.id,
        'name': skill.name,
        'description': skill.description,
        'category': skill.category
    }), 200

# Create a new skill (likely admin-only or protected)
@bp.route('', methods=['POST'])
@jwt_required() # Assuming only logged-in users can create skills
def create_skill():
    data = request.get_json()
    if not data or not data.get('name'):
        return jsonify({"message": "Skill name is required"}), 400

    if Skill.query.filter_by(name=data['name']).first():
        return jsonify({"message": f"Skill '{data['name']}' already exists"}), 400

    skill = Skill(
        name=data['name'],
        description=data.get('description'),
        category=data.get('category', 'General')
    )
    db.session.add(skill)
    db.session.commit()

    return jsonify({
        'message': "Skill created successfully",
        'skill': {
            'id': skill.id,
            'name': skill.name,
            'description': skill.description,
            'category': skill.category
        }
    }), 201

# Update a skill (likely admin-only or protected)
@bp.route('/<int:skill_id>', methods=['PUT'])
@jwt_required()
def update_skill(skill_id):
    skill = Skill.query.get_or_404(skill_id)
    data = request.get_json()

    # Check for uniqueness if name is being changed
    new_name = data.get('name')
    if new_name and new_name != skill.name and Skill.query.filter_by(name=new_name).first():
        return jsonify({"message": f"Skill name '{new_name}' already exists"}), 400

    skill.name = new_name or skill.name
    skill.description = data.get('description', skill.description)
    skill.category = data.get('category', skill.category)

    db.session.commit()
    return jsonify({
         'message': "Skill updated successfully",
         'skill': {
            'id': skill.id,
            'name': skill.name,
            'description': skill.description,
            'category': skill.category
        }
    }), 200

# Delete a skill (likely admin-only or protected)
@bp.route('/<int:skill_id>', methods=['DELETE'])
@jwt_required()
def delete_skill(skill_id):
    skill = Skill.query.get_or_404(skill_id)

    # Check if skill is associated with any users? Optional based on requirements.
    # if skill.users:
    #     return jsonify({"message": "Cannot delete skill associated with users"}), 400

    db.session.delete(skill)
    db.session.commit()
    return jsonify({"message": "Skill deleted successfully"}), 200 