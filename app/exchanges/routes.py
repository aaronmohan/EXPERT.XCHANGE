from flask import request, jsonify
from flask_jwt_extended import jwt_required, get_jwt_identity
from app import db
from app.models import User, Skill, SkillExchangeRequest, Notification
from app.exchanges import bp

# Create a new skill exchange request
@bp.route('', methods=['POST'])
@jwt_required()
def create_exchange_request():
    current_user_id = get_jwt_identity()
    data = request.get_json()

    requester = User.query.get_or_404(current_user_id)
    offered_skill_id = data.get('offered_skill_id')
    requested_skill_id = data.get('requested_skill_id')
    message = data.get('message')

    if not offered_skill_id or not requested_skill_id:
        return jsonify({"message": "Both offered and requested skill IDs are required"}), 400

    offered_skill = Skill.query.get(offered_skill_id)
    requested_skill = Skill.query.get(requested_skill_id)

    if not offered_skill or not requested_skill:
        return jsonify({"message": "One or both skills not found"}), 404

    # Find the owner of the requested skill
    # This assumes a skill is directly linked to a user or find users who have this skill
    # For simplicity, let's assume we need the requested_user_id in the payload
    requested_user_id = data.get('requested_user_id')
    if not requested_user_id:
         return jsonify({"message": "Requested user ID is required"}), 400

    requested_user = User.query.get(requested_user_id)
    if not requested_user:
         return jsonify({"message": "Requested user not found"}), 404

    # Check if the requester has the offered skill and requested user has the requested skill
    if offered_skill not in requester.skills:
        return jsonify({"message": "You do not possess the offered skill."}), 400
    if requested_skill not in requested_user.skills:
         return jsonify({"message": "The requested user does not possess the requested skill."}), 400

    exchange_request = SkillExchangeRequest(
        requester_id=current_user_id,
        requested_user_id=requested_user_id,
        offered_skill_id=offered_skill_id,
        requested_skill_id=requested_skill_id,
        message=message
    )
    db.session.add(exchange_request)

    # Create a notification for the requested user
    notification = Notification(
        user_id=requested_user_id,
        message=f"{requester.username} wants to exchange {offered_skill.name} for your {requested_skill.name}.",
        related_url=f'/api/exchanges/{exchange_request.id}' # Placeholder URL
    )
    db.session.add(notification)

    db.session.commit()

    return jsonify({
        "message": "Exchange request created successfully",
        "request_id": exchange_request.id
        }), 201

# Get all exchange requests relevant to the current user (sent or received)
@bp.route('', methods=['GET'])
@jwt_required()
def get_exchange_requests():
    current_user_id = get_jwt_identity()
    # Consider adding status filtering (e.g., ?status=pending)
    sent = SkillExchangeRequest.query.filter_by(requester_id=current_user_id).all()
    received = SkillExchangeRequest.query.filter_by(requested_user_id=current_user_id).all()

    def format_request(req):
        return {
            'id': req.id,
            'requester_id': req.requester_id,
            'requester_username': req.requester.username,
            'requested_user_id': req.requested_user_id,
            'requested_username': req.requested_user.username,
            'offered_skill': req.offered_skill.name,
            'requested_skill': req.requested_skill.name,
            'status': req.status,
            'message': req.message,
            'request_timestamp': req.request_timestamp.isoformat(),
            'response_timestamp': req.response_timestamp.isoformat() if req.response_timestamp else None
        }

    return jsonify({
        'sent': [format_request(req) for req in sent],
        'received': [format_request(req) for req in received]
    }), 200

# Get a specific exchange request
@bp.route('/<int:request_id>', methods=['GET'])
@jwt_required()
def get_exchange_request(request_id):
    current_user_id = get_jwt_identity()
    exchange_request = SkillExchangeRequest.query.get_or_404(request_id)

    # Ensure the current user is part of this exchange
    if exchange_request.requester_id != current_user_id and exchange_request.requested_user_id != current_user_id:
        return jsonify({"message": "Unauthorized to view this request"}), 403

    def format_request(req): # Duplicated formatting logic, consider helper function
        return {
            'id': req.id,
            'requester_id': req.requester_id,
            'requester_username': req.requester.username,
            'requested_user_id': req.requested_user_id,
            'requested_username': req.requested_user.username,
            'offered_skill': req.offered_skill.name,
            'requested_skill': req.requested_skill.name,
            'status': req.status,
            'message': req.message,
            'request_timestamp': req.request_timestamp.isoformat(),
            'response_timestamp': req.response_timestamp.isoformat() if req.response_timestamp else None
        }

    return jsonify(format_request(exchange_request)), 200

# Respond to an exchange request (accept/reject)
@bp.route('/<int:request_id>/respond', methods=['PUT'])
@jwt_required()
def respond_exchange_request(request_id):
    current_user_id = get_jwt_identity()
    exchange_request = SkillExchangeRequest.query.get_or_404(request_id)
    data = request.get_json()
    action = data.get('action') # Expecting 'accept' or 'reject'

    # Only the requested user can respond
    if exchange_request.requested_user_id != current_user_id:
        return jsonify({"message": "Unauthorized to respond to this request"}), 403

    if exchange_request.status != 'pending':
         return jsonify({"message": "Request is not pending"}), 400

    if action == 'accept':
        exchange_request.status = 'accepted'
        # Create notification for requester
        notification_msg = f"{exchange_request.requested_user.username} accepted your exchange request for {exchange_request.requested_skill.name}."
    elif action == 'reject':
        exchange_request.status = 'rejected'
        # Create notification for requester
        notification_msg = f"{exchange_request.requested_user.username} rejected your exchange request for {exchange_request.requested_skill.name}."
    else:
        return jsonify({"message": "Invalid action. Use 'accept' or 'reject'"}), 400

    exchange_request.response_timestamp = datetime.now(timezone.utc)

    # Create notification for the original requester
    notification = Notification(
        user_id=exchange_request.requester_id,
        message=notification_msg,
        related_url=f'/api/exchanges/{exchange_request.id}' # Placeholder URL
    )
    db.session.add(notification)

    db.session.commit()

    return jsonify({"message": f"Request {action}ed successfully"}), 200

# Cancel an exchange request (only requester can cancel pending requests)
@bp.route('/<int:request_id>/cancel', methods=['PUT'])
@jwt_required()
def cancel_exchange_request(request_id):
    current_user_id = get_jwt_identity()
    exchange_request = SkillExchangeRequest.query.get_or_404(request_id)

    # Only the requester can cancel
    if exchange_request.requester_id != current_user_id:
        return jsonify({"message": "Unauthorized to cancel this request"}), 403

    if exchange_request.status != 'pending':
        return jsonify({"message": "Only pending requests can be cancelled"}), 400

    exchange_request.status = 'cancelled'
    exchange_request.response_timestamp = datetime.now(timezone.utc) # Mark cancellation time

    # Optional: Notify the other user
    # notification = Notification(...)
    # db.session.add(notification)

    db.session.commit()
    return jsonify({"message": "Request cancelled successfully"}), 200

# Mark an exchange as completed (could be done by either party after acceptance)
# Might involve credit transfer
@bp.route('/<int:request_id>/complete', methods=['PUT'])
@jwt_required()
def complete_exchange_request(request_id):
    current_user_id = get_jwt_identity()
    exchange_request = SkillExchangeRequest.query.get_or_404(request_id)

    # Allow either party to mark as complete if accepted
    if exchange_request.requester_id != current_user_id and exchange_request.requested_user_id != current_user_id:
         return jsonify({"message": "Unauthorized"}), 403

    if exchange_request.status != 'accepted':
        return jsonify({"message": "Request must be accepted before completing"}), 400

    exchange_request.status = 'completed'

    # --- Credit Transfer Logic --- (Example: Requester pays 1 credit)
    requester_credit = UserCredit.query.filter_by(user_id=exchange_request.requester_id).first()
    requested_user_credit = UserCredit.query.filter_by(user_id=exchange_request.requested_user_id).first()

    if requester_credit and requested_user_credit:
        if requester_credit.balance >= 1:
            requester_credit.balance -= 1
            requested_user_credit.balance += 1
        else:
            # Handle insufficient credits - maybe revert status or just log?
            db.session.rollback() # Important to rollback if part fails
            return jsonify({"message": "Requester has insufficient credits. Cannot complete."}), 400
    else:
        db.session.rollback()
        return jsonify({"message": "Credit records not found for one or both users."}), 500
    # --- End Credit Transfer --- #

    # Optional: Create notifications for completion
    # notification_requester = Notification(...)
    # notification_requested = Notification(...)
    # db.session.add_all([notification_requester, notification_requested])

    db.session.commit()
    return jsonify({"message": "Exchange marked as completed successfully. Credit transferred."}) , 200 